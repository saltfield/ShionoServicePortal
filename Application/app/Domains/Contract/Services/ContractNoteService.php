<?php

namespace App\Domains\Contract\Services;

use App\Domains\Support\Services\EntityNoteService;
use App\Models\Contract;
use App\Models\EntityNote;
use App\Models\User;

/**
 * @deprecated Use EntityNoteService directly.
 */
class ContractNoteService
{
    public const MAX_BODY_LENGTH = EntityNoteService::MAX_BODY_LENGTH;

    public function __construct(private readonly EntityNoteService $notes) {}

    public function sharedNote(Contract $contract): ?EntityNote
    {
        return $this->notes->sharedNote($contract);
    }

    public function organizationNote(Contract $contract, User $actor): ?EntityNote
    {
        return $this->notes->organizationNote($contract, $actor);
    }

    public function upsertShared(User $actor, Contract $contract, ?string $body): EntityNote
    {
        return $this->notes->upsertShared($actor, $contract, $body);
    }

    public function upsertOrganization(User $actor, Contract $contract, ?string $body): EntityNote
    {
        return $this->notes->upsertOrganization($actor, $contract, $body);
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function ownerScope(User $actor): array
    {
        return $this->notes->ownerScope($actor);
    }
}
