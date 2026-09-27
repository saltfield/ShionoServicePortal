<?php

namespace App\Domains\Support\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Contract\Enums\ContractNoteVisibility;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\AuditLogger;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EntityNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class EntityNoteService
{
    public const MAX_BODY_LENGTH = 100000;

    public function __construct(
        private readonly ContractService $contracts,
        private readonly OrganizationMasterService $organization,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function sharedNote(Model $subject): ?EntityNote
    {
        return EntityNote::query()
            ->with('updatedBy')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('visibility', ContractNoteVisibility::Shared)
            ->where('owner_type', '_')
            ->where('owner_id', 0)
            ->first();
    }

    public function organizationNote(Model $subject, User $actor): ?EntityNote
    {
        [$ownerType, $ownerId] = $this->ownerScope($actor);

        return EntityNote::query()
            ->with('updatedBy')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('visibility', ContractNoteVisibility::Organization)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();
    }

    public function upsertShared(User $actor, Model $subject, ?string $body): EntityNote
    {
        $this->assertCanManageNotes($actor, $subject);

        return $this->upsert(
            $actor,
            $subject,
            ContractNoteVisibility::Shared,
            '_',
            0,
            $body,
        );
    }

    public function upsertOrganization(User $actor, Model $subject, ?string $body): EntityNote
    {
        $this->assertCanManageNotes($actor, $subject);
        [$ownerType, $ownerId] = $this->ownerScope($actor);

        return $this->upsert(
            $actor,
            $subject,
            ContractNoteVisibility::Organization,
            $ownerType,
            $ownerId,
            $body,
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function ownerScope(User $actor): array
    {
        return match ($actor->user_type) {
            UserType::Admin => ['admin', 0],
            UserType::Bp => ['bp', (int) $actor->bp_id],
            UserType::Customer => ['customer', (int) $actor->customer_id],
        };
    }

    private function assertCanManageNotes(User $actor, Model $subject): void
    {
        if ($subject instanceof Contract) {
            $this->contracts->assertActorCanAccessContract($actor, $subject);

            return;
        }

        if ($subject instanceof BusinessPartner) {
            if ($actor->user_type === UserType::Customer) {
                abort(403);
            }
            $this->organization->ensureBpInScope($actor, $subject);

            return;
        }

        if ($subject instanceof Customer) {
            if ($actor->user_type === UserType::Customer) {
                abort_unless((int) $actor->customer_id === (int) $subject->id, 403);

                return;
            }
            $this->organization->ensureCustomerInScope($actor, $subject);

            return;
        }

        throw new InvalidArgumentException('この対象には備考を保存できません。');
    }

    private function upsert(
        User $actor,
        Model $subject,
        ContractNoteVisibility $visibility,
        string $ownerType,
        int $ownerId,
        ?string $body,
    ): EntityNote {
        $normalized = $this->normalizeBody($body);

        $note = EntityNote::query()->updateOrCreate(
            [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'visibility' => $visibility->value,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ],
            [
                'body' => $normalized,
                'updated_by_user_id' => $actor->id,
            ],
        );

        $category = match (true) {
            $subject instanceof Contract => 'contract',
            $subject instanceof BusinessPartner => 'bp',
            $subject instanceof Customer => 'customer',
            default => 'note',
        };

        $this->auditLogger->log(
            category: $category,
            action: $visibility === ContractNoteVisibility::Shared
                ? $category.'.note.shared.upsert'
                : $category.'.note.organization.upsert',
            result: 'success',
            actor: $actor,
            targetType: $subject::class,
            targetId: (int) $subject->getKey(),
            meta: [
                'note_id' => $note->id,
                'visibility' => $visibility->value,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'body_length' => mb_strlen($normalized ?? ''),
            ],
        );

        if ($subject instanceof Contract) {
            $this->contracts->recordNoteHistory(
                $actor,
                $subject,
                $visibility === ContractNoteVisibility::Shared ? '共有備考を更新' : '組織内備考を更新',
            );
        }

        return $note->load('updatedBy');
    }

    private function normalizeBody(?string $body): ?string
    {
        $normalized = $body === null ? null : rtrim(str_replace("\r\n", "\n", $body));
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('備考は'.self::MAX_BODY_LENGTH.'文字以内で入力してください。');
        }

        return $normalized;
    }
}
