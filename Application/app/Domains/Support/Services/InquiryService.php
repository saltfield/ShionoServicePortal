<?php

namespace App\Domains\Support\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Support\Enums\InquiryAssigneeType;
use App\Domains\Support\Enums\InquiryMessageType;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Enums\InquiryVisibility;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Models\InquiryMessageAttachment;
use App\Models\InquiryRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class InquiryService
{
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    public const ALLOWED_ATTACHMENT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'heic', 'heif', 'pdf'];

    /** @var list<string> */
    public const ALLOWED_ATTACHMENT_MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
        'application/pdf',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
        private readonly NumberSequenceService $sequences,
    ) {}

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function open(
        User $actor,
        string $subject,
        string $body,
        InquiryVisibility $visibility = InquiryVisibility::Organization,
        ?Customer $customer = null,
        bool $proxyForCustomer = false,
        array $attachments = [],
        ?BusinessPartner $proxyBp = null,
    ): Inquiry {
        if ($actor->user_type === UserType::Admin) {
            throw new InvalidArgumentException('管理者はチケットを起票できません。');
        }

        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException('件名と本文は必須です。');
        }

        $this->assertAttachments($attachments);
        $resolved = $this->resolveOpenTargets($actor, $customer, $proxyForCustomer, $proxyBp);

        $this->authorization->authorize($actor, 'inquiry.reply', [
            'resource_type' => 'inquiry',
            'owner_bp_id' => $resolved['assignee_bp_id'],
            'customer_id' => $resolved['customer_id'],
            'status' => InquiryStatus::Submitted->value,
        ]);

        return DB::transaction(function () use ($actor, $subject, $body, $visibility, $resolved, $attachments) {
            $inquiry = Inquiry::query()->create([
                'code' => $this->sequences->next(PartnerCodePrefix::Ticket),
                'assignee_type' => $resolved['assignee_type'],
                'assignee_bp_id' => $resolved['assignee_bp_id'],
                'subject' => $subject,
                'status' => InquiryStatus::Submitted,
                'visibility' => $visibility,
                'opened_by_user_id' => $actor->id,
                'issuer_bp_id' => $resolved['issuer_bp_id'],
                'customer_id' => $resolved['customer_id'],
            ]);

            $message = InquiryMessage::query()->create([
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
                'message_type' => InquiryMessageType::User,
                'body' => $body,
            ]);

            $this->storeAttachments($message, $attachments);
            $this->markRead($actor, $inquiry);

            $this->auditLogger->log(
                'inquiry',
                'inquiry.open',
                'success',
                $actor,
                targetType: Inquiry::class,
                targetId: $inquiry->id,
                meta: ['code' => $inquiry->code, 'subject' => $subject],
            );

            return $inquiry->fresh(['messages.attachments']);
        });
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function reply(User $actor, Inquiry $inquiry, string $body, array $attachments = []): InquiryMessage
    {
        $body = trim($body);
        if ($body === '' && $attachments === []) {
            throw new InvalidArgumentException('本文または添付が必要です。');
        }
        if ($body === '') {
            $body = '（添付ファイル）';
        }

        $this->assertAttachments($attachments);
        $this->assertVisible($actor, $inquiry);
        $this->assertCanReply($inquiry);
        $this->authorization->authorize($actor, 'inquiry.reply', $inquiry);

        return DB::transaction(function () use ($actor, $inquiry, $body, $attachments) {
            if ($inquiry->status === InquiryStatus::Submitted && $this->isAssigneeSide($actor, $inquiry)) {
                $this->changeStatus($actor, $inquiry, InquiryStatus::InProgress, recordHistory: true);
            }

            $message = InquiryMessage::query()->create([
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
                'message_type' => InquiryMessageType::User,
                'body' => $body,
            ]);

            $this->storeAttachments($message, $attachments);
            $inquiry->touch();
            $this->markRead($actor, $inquiry);

            $this->auditLogger->log(
                'inquiry',
                'inquiry.reply',
                'success',
                $actor,
                targetType: Inquiry::class,
                targetId: $inquiry->id,
            );

            return $message->load('attachments');
        });
    }

    public function withdraw(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        if ((int) $inquiry->opened_by_user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('取下げは起票者のみ可能です。');
        }
        if ($inquiry->status !== InquiryStatus::Submitted) {
            throw new InvalidArgumentException('取下げは起票ステータスのときのみ可能です。');
        }

        return $this->changeStatus($actor, $inquiry, InquiryStatus::Withdrawn, recordHistory: true);
    }

    public function startProgress(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        $this->assertAssigneeSide($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.reply', $inquiry);

        if ($inquiry->status !== InquiryStatus::Submitted) {
            throw new InvalidArgumentException('起票中のチケットのみ受領対応を開始できます。');
        }

        return $this->changeStatus($actor, $inquiry, InquiryStatus::InProgress, recordHistory: true);
    }

    public function close(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        $this->assertAssigneeSide($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.close', $inquiry);

        if ($inquiry->status !== InquiryStatus::InProgress) {
            throw new InvalidArgumentException('クローズは受領対応中のチケットのみ可能です。');
        }

        $inquiry = $this->changeStatus($actor, $inquiry, InquiryStatus::Closed, recordHistory: true);
        $inquiry->closed_at = now();
        $inquiry->save();

        return $inquiry->fresh();
    }

    public function reopen(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        $this->assertAssigneeSide($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.reopen', $inquiry);

        if ($inquiry->status !== InquiryStatus::Closed) {
            throw new InvalidArgumentException('クローズ済みのチケットのみ再オープンできます。');
        }

        $inquiry = $this->changeStatus($actor, $inquiry, InquiryStatus::InProgress, recordHistory: true);
        $inquiry->closed_at = null;
        $inquiry->save();

        return $inquiry->fresh();
    }

    public function delete(User $actor, Inquiry $inquiry): void
    {
        $this->assertVisible($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.close', $inquiry);

        $inquiry->delete();

        $this->auditLogger->log(
            'inquiry',
            'inquiry.delete',
            'success',
            $actor,
            targetType: Inquiry::class,
            targetId: $inquiry->id,
        );
    }

    /**
     * @return Builder<Inquiry>
     */
    public function applyListFilters(Builder $query, bool $includeClosed = false, string $search = ''): Builder
    {
        if (! $includeClosed) {
            $query->where('status', '!=', InquiryStatus::Closed->value);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($like) {
                $builder->where('inquiries.code', 'like', $like)
                    ->orWhere('inquiries.subject', 'like', $like)
                    ->orWhereHas('customer', function (Builder $customer) use ($like) {
                        $customer->where('code', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    })
                    ->orWhereHas('issuerBp', function (Builder $bp) use ($like) {
                        $bp->where('code', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    })
                    ->orWhereHas('assigneeBp', function (Builder $bp) use ($like) {
                        $bp->where('code', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    });
            });
        }

        return $query;
    }

    /**
     * @return Builder<Inquiry>
     */
    public function receivedQuery(User $actor): Builder
    {
        $this->authorization->authorize($actor, 'inquiry.view');

        $query = Inquiry::query()->with(['customer', 'assigneeBp', 'openedBy', 'issuerBp']);

        return match ($actor->user_type) {
            UserType::Admin => $query->where('assignee_type', InquiryAssigneeType::Admin->value),
            UserType::Bp => $query
                ->where('assignee_type', InquiryAssigneeType::Bp->value)
                ->where('assignee_bp_id', $actor->bp_id),
            UserType::Customer => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return Builder<Inquiry>
     */
    public function issuedQuery(User $actor): Builder
    {
        $this->authorization->authorize($actor, 'inquiry.view');

        $query = Inquiry::query()->with(['customer', 'assigneeBp', 'openedBy', 'issuerBp']);

        return match ($actor->user_type) {
            UserType::Admin => $query->whereRaw('1 = 0'),
            UserType::Customer => $query
                ->where('customer_id', $actor->customer_id)
                ->where(function (Builder $builder) use ($actor) {
                    $builder->where('visibility', InquiryVisibility::Organization->value)
                        ->orWhere('opened_by_user_id', $actor->id);
                }),
            UserType::Bp => $query
                ->where('issuer_bp_id', $actor->bp_id)
                ->where(function (Builder $builder) use ($actor) {
                    $builder->where('visibility', InquiryVisibility::Organization->value)
                        ->orWhere('opened_by_user_id', $actor->id);
                }),
        };
    }

    /**
     * @return Builder<Inquiry>
     */
    public function visibleQuery(User $actor): Builder
    {
        $ids = $this->receivedQuery($actor)->pluck('id')
            ->merge($this->issuedQuery($actor)->pluck('id'))
            ->unique()
            ->values()
            ->all();

        return Inquiry::query()
            ->with(['customer', 'assigneeBp', 'openedBy', 'issuerBp'])
            ->whereIn('id', $ids === [] ? [0] : $ids);
    }

    public function assertVisible(User $actor, Inquiry $inquiry): void
    {
        $visible = $this->receivedQuery($actor)->whereKey($inquiry->id)->exists()
            || $this->issuedQuery($actor)->whereKey($inquiry->id)->exists();

        if (! $visible) {
            abort(403, 'このチケットを参照する権限がありません。');
        }
    }

    /**
     * 管理者による組織詳細からの閲覧（操作不可）。
     */
    public function assertAdminInspectable(User $actor, Inquiry $inquiry): void
    {
        if ($actor->user_type !== UserType::Admin) {
            abort(403, 'このチケットを参照する権限がありません。');
        }

        $this->authorization->authorize($actor, 'inquiry.view');
    }

    /**
     * @return Builder<Inquiry>
     */
    public function adminBpReceivedQuery(BusinessPartner $partner): Builder
    {
        return Inquiry::query()
            ->with(['customer', 'assigneeBp', 'openedBy', 'issuerBp'])
            ->where('assignee_type', InquiryAssigneeType::Bp->value)
            ->where('assignee_bp_id', $partner->id);
    }

    /**
     * @return Builder<Inquiry>
     */
    public function adminCustomerTicketsQuery(Customer $customer): Builder
    {
        return Inquiry::query()
            ->with(['customer', 'assigneeBp', 'openedBy', 'issuerBp'])
            ->where('customer_id', $customer->id);
    }

    public function markRead(User $actor, Inquiry $inquiry): void
    {
        $lastMessageId = $inquiry->messages()->max('id');

        InquiryRead::query()->updateOrCreate(
            [
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
            ],
            [
                'last_read_at' => now(),
                'last_read_message_id' => $lastMessageId,
            ],
        );
    }

    public function unreadReceivedCount(User $actor): int
    {
        if (! $this->authorization->can($actor, 'inquiry.view')) {
            return 0;
        }

        return $this->countUnread($actor, $this->receivedQuery($actor));
    }

    public function unreadIssuedCount(User $actor): int
    {
        if (! $this->authorization->can($actor, 'inquiry.view')) {
            return 0;
        }

        return $this->countUnread($actor, $this->issuedQuery($actor));
    }

    public function unreadCount(User $actor): int
    {
        if (! $this->authorization->can($actor, 'inquiry.view')) {
            return 0;
        }

        return $this->countUnread($actor, $this->visibleQuery($actor));
    }

    public function isUnread(User $actor, Inquiry $inquiry): bool
    {
        $lastMessage = $inquiry->messages()
            ->where('message_type', InquiryMessageType::User->value)
            ->latest('id')
            ->first();

        if ($lastMessage === null || (int) $lastMessage->user_id === (int) $actor->id) {
            return false;
        }

        $read = InquiryRead::query()
            ->where('inquiry_id', $inquiry->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($read === null || $read->last_read_message_id === null) {
            return true;
        }

        return (int) $lastMessage->id > (int) $read->last_read_message_id;
    }

    public function isAssigneeSide(User $actor, Inquiry $inquiry): bool
    {
        if ($actor->user_type === UserType::Admin) {
            return $inquiry->assignee_type === InquiryAssigneeType::Admin;
        }

        if ($actor->user_type === UserType::Bp) {
            return $inquiry->assignee_type === InquiryAssigneeType::Bp
                && (int) $inquiry->assignee_bp_id === (int) $actor->bp_id;
        }

        return false;
    }

    public function isIssuerSide(User $actor, Inquiry $inquiry): bool
    {
        if ($actor->user_type === UserType::Customer) {
            return (int) $inquiry->customer_id === (int) $actor->customer_id;
        }

        if ($actor->user_type === UserType::Bp) {
            return (int) $inquiry->issuer_bp_id === (int) $actor->bp_id;
        }

        return false;
    }

    /**
     * @return array{assignee_type: InquiryAssigneeType, assignee_bp_id: int|null, customer_id: int|null, issuer_bp_id: int|null}
     */
    private function resolveOpenTargets(
        User $actor,
        ?Customer $customer,
        bool $proxyForCustomer,
        ?BusinessPartner $proxyBp = null,
    ): array {
        if ($actor->user_type === UserType::Customer) {
            $actor->loadMissing('customer.managingBp');
            if ($actor->customer === null) {
                throw new InvalidArgumentException('カスタマーが紐づいていません。');
            }

            return [
                'assignee_type' => InquiryAssigneeType::Bp,
                'assignee_bp_id' => (int) $actor->customer->managing_bp_id,
                'customer_id' => (int) $actor->customer_id,
                'issuer_bp_id' => null,
            ];
        }

        if ($actor->user_type !== UserType::Bp) {
            throw new InvalidArgumentException('チケットを起票する権限がありません。');
        }

        $actor->loadMissing('businessPartner.parent');
        $actorBp = $actor->businessPartner;
        if ($actorBp === null) {
            throw new InvalidArgumentException('BPが紐づいていません。');
        }

        if ($proxyForCustomer || $proxyBp !== null) {
            if ($proxyForCustomer && $proxyBp !== null) {
                throw new InvalidArgumentException('代理起票の対象はBPかカスタマーのどちらか一方を指定してください。');
            }

            if ($proxyForCustomer) {
                if ($customer === null) {
                    throw new InvalidArgumentException('代理起票するカスタマーを指定してください。');
                }
                $scope = $this->hierarchy->descendantIdsIncludingSelf($actorBp);
                if (! in_array((int) $customer->managing_bp_id, $scope, true)) {
                    throw new InvalidArgumentException('配下外のカスタマーには起票できません。');
                }

                return [
                    'assignee_type' => InquiryAssigneeType::Bp,
                    'assignee_bp_id' => (int) $actor->bp_id,
                    'customer_id' => (int) $customer->id,
                    'issuer_bp_id' => (int) $actor->bp_id,
                ];
            }

            if ($proxyBp === null) {
                throw new InvalidArgumentException('代理起票するBPを指定してください。');
            }

            if (! $this->hierarchy->isDescendant($actorBp, $proxyBp)) {
                throw new InvalidArgumentException('配下外のBPには起票できません。');
            }

            return [
                'assignee_type' => InquiryAssigneeType::Bp,
                'assignee_bp_id' => (int) $actor->bp_id,
                'customer_id' => null,
                'issuer_bp_id' => (int) $proxyBp->id,
            ];
        }

        if ($customer !== null) {
            throw new InvalidArgumentException('上位への相談起票ではカスタマーを指定できません。');
        }

        if ($actorBp->parent_id) {
            return [
                'assignee_type' => InquiryAssigneeType::Bp,
                'assignee_bp_id' => (int) $actorBp->parent_id,
                'customer_id' => null,
                'issuer_bp_id' => (int) $actor->bp_id,
            ];
        }

        return [
            'assignee_type' => InquiryAssigneeType::Admin,
            'assignee_bp_id' => null,
            'customer_id' => null,
            'issuer_bp_id' => (int) $actor->bp_id,
        ];
    }

    private function changeStatus(User $actor, Inquiry $inquiry, InquiryStatus $to, bool $recordHistory): Inquiry
    {
        $from = $inquiry->status;
        if ($from === $to) {
            return $inquiry;
        }

        $inquiry->status = $to;
        $inquiry->save();

        if ($recordHistory) {
            InquiryMessage::query()->create([
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
                'message_type' => InquiryMessageType::StatusChange,
                'body' => sprintf('ステータスを「%s」から「%s」に変更しました。', $from->label(), $to->label()),
                'from_status' => $from->value,
                'to_status' => $to->value,
            ]);
            $inquiry->touch();
        }

        $this->auditLogger->log(
            'inquiry',
            'inquiry.status_change',
            'success',
            $actor,
            targetType: Inquiry::class,
            targetId: $inquiry->id,
            meta: ['from' => $from->value, 'to' => $to->value],
        );

        return $inquiry->fresh();
    }

    private function assertCanReply(Inquiry $inquiry): void
    {
        if (in_array($inquiry->status, [InquiryStatus::Closed, InquiryStatus::Withdrawn], true)) {
            throw new InvalidArgumentException('このステータスではメッセージを送信できません。');
        }
    }

    private function assertAssigneeSide(User $actor, Inquiry $inquiry): void
    {
        if (! $this->isAssigneeSide($actor, $inquiry)) {
            throw new InvalidArgumentException('この操作は受領側のみ可能です。');
        }
    }

    /**
     * @param  Builder<Inquiry>  $query
     */
    private function countUnread(User $actor, Builder $query): int
    {
        $ids = (clone $query)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach (Inquiry::query()->whereIn('id', $ids)->with(['messages' => fn ($q) => $q->where('message_type', InquiryMessageType::User->value)->latest('id')->limit(1), 'reads' => fn ($q) => $q->where('user_id', $actor->id)])->get() as $inquiry) {
            if ($this->isUnread($actor, $inquiry)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    private function assertAttachments(array $attachments): void
    {
        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('添付は1メッセージあたり最大'.self::MAX_ATTACHMENTS.'ファイルです。');
        }

        foreach ($attachments as $file) {
            if (! $file instanceof UploadedFile) {
                throw new InvalidArgumentException('不正な添付ファイルです。');
            }
            if (! $file->isValid()) {
                throw new InvalidArgumentException('添付ファイルのアップロードに失敗しました。');
            }
            if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
                throw new InvalidArgumentException('添付ファイルは1ファイルあたり最大10MBです。');
            }
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_ATTACHMENT_EXTENSIONS, true)) {
                throw new InvalidArgumentException('添付できる形式は PNG / JPEG / GIF / HEIC / HEIF / PDF です。');
            }
            $mime = (string) $file->getMimeType();
            if ($mime !== '' && ! in_array($mime, self::ALLOWED_ATTACHMENT_MIMES, true)) {
                // HEIC browsers sometimes report octet-stream; allow by extension.
                if (! in_array($ext, ['heic', 'heif'], true) || $mime !== 'application/octet-stream') {
                    throw new InvalidArgumentException('添付ファイルの形式が不正です。');
                }
            }
        }
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    private function storeAttachments(InquiryMessage $message, array $attachments): void
    {
        foreach ($attachments as $file) {
            $path = $file->store(
                'ticket-attachments/'.$message->inquiry_id.'/'.$message->id,
                'local'
            );

            InquiryMessageAttachment::query()->create([
                'inquiry_message_id' => $message->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
            ]);
        }
    }
}
