<?php

namespace App\Domains\Notification\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Support\Enums\InquiryAssigneeType;
use App\Domains\Support\Enums\InquiryVisibility;
use App\Models\Application;
use App\Models\Inquiry;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function ticketAssigneeSide(Inquiry $inquiry): Collection
    {
        if ($inquiry->assignee_type === InquiryAssigneeType::Admin) {
            return $this->dispatcher->usersWithPermission(null, 'inquiry.view', includeAdmins: true)
                ->filter(fn (User $user) => $user->user_type === UserType::Admin)
                ->values();
        }

        if ($inquiry->assignee_bp_id === null) {
            return collect();
        }

        return $this->dispatcher->usersWithPermission((int) $inquiry->assignee_bp_id, 'inquiry.view');
    }

    /**
     * @return Collection<int, User>
     */
    public function ticketIssuerSide(Inquiry $inquiry): Collection
    {
        if ($inquiry->visibility === InquiryVisibility::Private) {
            return collect([$inquiry->openedBy])->filter();
        }

        if ($inquiry->customer_id !== null) {
            return User::query()
                ->where('user_type', UserType::Customer->value)
                ->where('customer_id', $inquiry->customer_id)
                ->where('is_active', true)
                ->get();
        }

        if ($inquiry->issuer_bp_id !== null) {
            return $this->dispatcher->usersWithPermission((int) $inquiry->issuer_bp_id, 'inquiry.view');
        }

        if ($inquiry->openedBy) {
            return collect([$inquiry->openedBy]);
        }

        return collect();
    }

    /**
     * @return Collection<int, User>
     */
    public function applicationApprovers(Application $application): Collection
    {
        $bpUsers = $this->dispatcher->usersWithPermission(
            (int) $application->to_bp_id,
            'contract.approve'
        );

        // 承認先BPに承認者がいない場合のみ管理者へフォールバック
        if ($bpUsers->isNotEmpty()) {
            return $bpUsers;
        }

        return $this->dispatcher->usersWithPermission(null, 'contract.approve', includeAdmins: true)
            ->filter(fn (User $user) => $user->user_type === UserType::Admin)
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function applicationRequester(Application $application): Collection
    {
        $submittedById = data_get($application->payload_json, 'submitted_by_user_id');
        if ($submittedById) {
            $user = User::query()->find((int) $submittedById);
            if ($user !== null) {
                return collect([$user]);
            }
        }

        return $this->dispatcher->usersWithPermission(
            (int) $application->from_bp_id,
            'contract.view'
        );
    }
}