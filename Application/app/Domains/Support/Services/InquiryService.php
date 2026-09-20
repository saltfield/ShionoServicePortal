<?php

namespace App\Domains\Support\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InquiryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
    ) {}

    public function open(
        User $actor,
        string $subject,
        string $body,
        ?Customer $customer = null,
        ?BusinessPartner $owningBp = null,
    ): Inquiry {
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException('件名と本文は必須です。');
        }

        $resolved = $this->resolveOpenTargets($actor, $customer, $owningBp);
        $this->authorization->authorize($actor, 'inquiry.reply', [
            'resource_type' => 'inquiry',
            'owner_bp_id' => $resolved['owning_bp_id'],
            'customer_id' => $resolved['customer_id'],
            'status' => InquiryStatus::Open->value,
        ]);

        return DB::transaction(function () use ($actor, $subject, $body, $resolved) {
            $inquiry = Inquiry::query()->create([
                'subject' => $subject,
                'status' => InquiryStatus::Open,
                'opened_by_user_id' => $actor->id,
                'customer_id' => $resolved['customer_id'],
                'owning_bp_id' => $resolved['owning_bp_id'],
            ]);

            InquiryMessage::query()->create([
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
                'body' => $body,
            ]);

            $this->auditLogger->log(
                'inquiry',
                'inquiry.open',
                'success',
                $actor,
                targetType: Inquiry::class,
                targetId: $inquiry->id,
                meta: ['subject' => $subject],
            );

            return $inquiry->fresh(['messages']);
        });
    }

    public function reply(User $actor, Inquiry $inquiry, string $body): InquiryMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('本文は必須です。');
        }

        $this->assertVisible($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.reply', $inquiry);

        return DB::transaction(function () use ($actor, $inquiry, $body) {
            if ($inquiry->status === InquiryStatus::Open
                && $actor->user_type !== UserType::Customer
            ) {
                $inquiry->status = InquiryStatus::InProgress;
                $inquiry->save();
            }

            $message = InquiryMessage::query()->create([
                'inquiry_id' => $inquiry->id,
                'user_id' => $actor->id,
                'body' => $body,
            ]);

            $inquiry->touch();

            $this->auditLogger->log(
                'inquiry',
                'inquiry.reply',
                'success',
                $actor,
                targetType: Inquiry::class,
                targetId: $inquiry->id,
            );

            return $message;
        });
    }

    public function close(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.close', $inquiry);

        if ($inquiry->status === InquiryStatus::Closed) {
            return $inquiry;
        }

        $inquiry->status = InquiryStatus::Closed;
        $inquiry->closed_at = now();
        $inquiry->save();

        $this->auditLogger->log(
            'inquiry',
            'inquiry.close',
            'success',
            $actor,
            targetType: Inquiry::class,
            targetId: $inquiry->id,
        );

        return $inquiry->fresh();
    }

    public function reopen(User $actor, Inquiry $inquiry): Inquiry
    {
        $this->assertVisible($actor, $inquiry);
        $this->authorization->authorize($actor, 'inquiry.reopen', $inquiry);

        if ($inquiry->status !== InquiryStatus::Closed) {
            throw new InvalidArgumentException('クローズ済みの問い合わせのみ再オープンできます。');
        }

        $inquiry->status = InquiryStatus::InProgress;
        $inquiry->closed_at = null;
        $inquiry->save();

        $this->auditLogger->log(
            'inquiry',
            'inquiry.reopen',
            'success',
            $actor,
            targetType: Inquiry::class,
            targetId: $inquiry->id,
        );

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
    public function visibleQuery(User $actor): Builder
    {
        $query = Inquiry::query()->with(['customer', 'owningBp', 'openedBy']);

        return match ($actor->user_type) {
            UserType::Admin => $query,
            UserType::Customer => $query->where('customer_id', $actor->customer_id),
            UserType::Bp => $query->whereIn(
                'owning_bp_id',
                $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner)
            ),
        };
    }

    public function assertVisible(User $actor, Inquiry $inquiry): void
    {
        $visible = $this->visibleQuery($actor)->whereKey($inquiry->id)->exists();
        if (! $visible) {
            abort(403, 'この問い合わせを参照する権限がありません。');
        }
    }

    /**
     * @return array{customer_id: int|null, owning_bp_id: int}
     */
    private function resolveOpenTargets(User $actor, ?Customer $customer, ?BusinessPartner $owningBp): array
    {
        if ($actor->user_type === UserType::Customer) {
            $actor->loadMissing('customer.managingBp');
            if ($actor->customer === null) {
                throw new InvalidArgumentException('カスタマーが紐づいていません。');
            }

            return [
                'customer_id' => $actor->customer_id,
                'owning_bp_id' => (int) $actor->customer->managing_bp_id,
            ];
        }

        if ($actor->user_type === UserType::Bp) {
            $actor->loadMissing('businessPartner');
            if ($customer !== null) {
                $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                if (! in_array($customer->managing_bp_id, $scope, true)) {
                    throw new InvalidArgumentException('配下外のカスタマーには問い合わせを作成できません。');
                }

                return [
                    'customer_id' => $customer->id,
                    'owning_bp_id' => (int) $customer->managing_bp_id,
                ];
            }

            return [
                'customer_id' => null,
                'owning_bp_id' => (int) $actor->bp_id,
            ];
        }

        // Admin
        if ($customer !== null) {
            return [
                'customer_id' => $customer->id,
                'owning_bp_id' => (int) $customer->managing_bp_id,
            ];
        }

        if ($owningBp !== null) {
            return [
                'customer_id' => null,
                'owning_bp_id' => $owningBp->id,
            ];
        }

        throw new InvalidArgumentException('カスタマーまたは対応BPを指定してください。');
    }
}
