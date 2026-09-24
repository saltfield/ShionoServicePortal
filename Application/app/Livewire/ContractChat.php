<?php

namespace App\Livewire;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\RbacService;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

class ContractChat extends Component
{
    public int $contractId;

    public string $routePrefix = 'admin';

    public string $body = '';

    public function mount(int $contractId, string $routePrefix = 'admin'): void
    {
        $this->contractId = $contractId;
        $this->routePrefix = $routePrefix;

        $actor = $this->actor();
        $this->assertVisible($actor, $this->contract());
        $this->service()->markMessagesRead($actor, $this->contract());
    }

    public function send(ContractService $service, RbacService $rbac): void
    {
        $actor = $this->actor();
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);

        $this->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $service->postMessage($actor, $this->contract(), (string) $this->body);
            $this->body = '';
        } catch (InvalidArgumentException $exception) {
            $this->addError('body', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $contract = $this->contract()->load(['messages.user']);

        return view('livewire.contract-chat', [
            'contract' => $contract,
            'canPost' => $this->service()->canPostMessages($contract),
        ]);
    }

    private function actor(): User
    {
        $user = auth($this->routePrefix)->user();
        if (! $user instanceof User) {
            foreach (['admin', 'bp', 'customer'] as $guard) {
                $candidate = auth($guard)->user();
                if ($candidate instanceof User) {
                    $this->routePrefix = $guard;

                    return $candidate;
                }
            }
            abort(403);
        }

        return $user;
    }

    private function contract(): Contract
    {
        return Contract::query()->findOrFail($this->contractId);
    }

    private function service(): ContractService
    {
        return app(ContractService::class);
    }

    private function assertVisible(User $actor, Contract $contract): void
    {
        if ($actor->user_type === UserType::Customer) {
            abort_unless((int) $actor->customer_id === (int) $contract->customer_id, 403);

            return;
        }

        if ($actor->user_type === UserType::Bp) {
            $actorBp = $actor->businessPartner;
            abort_unless($actorBp, 403);
            $ids = app(BpHierarchyService::class)->descendantIdsIncludingSelf($actorBp);
            abort_unless(in_array((int) $contract->owning_bp_id, array_map('intval', $ids), true), 403);
        }
    }
}
