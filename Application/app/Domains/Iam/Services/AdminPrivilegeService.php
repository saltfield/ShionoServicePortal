<?php

namespace App\Domains\Iam\Services;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Notification\Services\NotificationService;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AdminPrivilegeService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifications,
    ) {}

    public function forcePasswordChange(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'admin.user.force_password');

        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => '自分自身にはパスワード強制変更を設定できません。',
            ]);
        }

        $target->forceFill(['must_change_password' => true])->save();

        $this->auditLogger->log(
            category: 'privilege',
            action: 'admin.user.force_password',
            result: 'success',
            actor: $actor,
            targetUser: $target,
        );

        $this->notifications->notifyForcePasswordChange($target, $actor);
    }

    public function forceDisableTwoFactor(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'admin.user.reset_2fa', [
            'resource_type' => '*',
        ]);

        $target->forceFill(['two_factor_forced_disabled' => true])->save();

        $this->auditLogger->log(
            category: 'privilege',
            action: 'admin.user.reset_2fa',
            result: 'success',
            actor: $actor,
            targetUser: $target,
            meta: ['two_factor_forced_disabled' => true],
        );
    }

    public function clearForceDisableTwoFactor(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'admin.user.reset_2fa', [
            'resource_type' => '*',
        ]);

        $target->forceFill(['two_factor_forced_disabled' => false])->save();

        $this->auditLogger->log(
            category: 'privilege',
            action: 'admin.user.clear_reset_2fa',
            result: 'success',
            actor: $actor,
            targetUser: $target,
            meta: ['two_factor_forced_disabled' => false],
        );
    }

    public function updateBpTwoFactorMode(User $actor, BusinessPartner $partner, TwoFactorMode $mode): void
    {
        $this->authorization->authorize($actor, 'admin.bp.two_factor.manage', [
            'resource_type' => 'bp',
            'owner_bp_id' => $partner->id,
        ]);

        $before = $partner->two_factor_mode?->value;

        $partner->forceFill(['two_factor_mode' => $mode])->save();

        $this->auditLogger->log(
            category: 'privilege',
            action: 'admin.bp.two_factor.manage',
            result: 'success',
            actor: $actor,
            targetType: BusinessPartner::class,
            targetId: $partner->id,
            meta: [
                'before' => $before,
                'after' => $mode->value,
                'bp_code' => $partner->code,
            ],
        );
    }

    public function updateCustomerTwoFactorMode(User $actor, Customer $customer, TwoFactorMode $mode): void
    {
        $this->authorization->authorize($actor, 'admin.bp.two_factor.manage', [
            'resource_type' => 'customer',
            'owner_bp_id' => $customer->managing_bp_id,
        ]);

        $before = $customer->two_factor_mode?->value;

        $customer->forceFill(['two_factor_mode' => $mode])->save();

        $this->auditLogger->log(
            category: 'privilege',
            action: 'admin.customer.two_factor.manage',
            result: 'success',
            actor: $actor,
            targetType: Customer::class,
            targetId: $customer->id,
            meta: [
                'before' => $before,
                'after' => $mode->value,
                'cn' => $customer->code,
                'managing_bp_id' => $customer->managing_bp_id,
            ],
        );
    }
}
