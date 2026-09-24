<?php

namespace App\Domains\Iam\Services;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Enums\RoleScope;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class UserManagementService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly RbacService $rbac,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): User
    {
        $this->authorization->authorize($actor, 'iam.user.manage');
        $this->assertActorCanManage($actor);

        $type = UserType::from($data['user_type']);
        $this->assertCreateAllowed($actor, $type, $data);

        $loginId = IdentifierNormalizer::normalize((string) $data['login_id']);
        if ($this->loginIdTaken($type, $loginId, $data)) {
            throw new InvalidArgumentException('このログインIDは当該組織で既に使用されています。');
        }

        return DB::transaction(function () use ($actor, $data, $type, $loginId) {
            $user = User::query()->create([
                'login_id' => $loginId,
                'password' => Hash::make((string) $data['password']),
                'user_type' => $type,
                'bp_id' => $type === UserType::Bp ? (int) $data['bp_id'] : null,
                'customer_id' => $type === UserType::Customer ? (int) $data['customer_id'] : null,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'must_change_password' => (bool) ($data['must_change_password'] ?? false),
            ]);

            $this->syncPrimaryRole($user, (string) $data['role_code']);

            $this->auditLogger->log(
                'iam',
                'user.create',
                'success',
                $actor,
                targetUser: $user,
                targetType: User::class,
                targetId: $user->id,
                meta: ['login_id' => $user->login_id, 'user_type' => $type->value],
            );

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->authorization->authorize($actor, 'iam.user.manage');
        $this->assertCanManageTarget($actor, $target);

        if ($actor->id === $target->id && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new InvalidArgumentException('自分自身を無効化できません。');
        }

        return DB::transaction(function () use ($actor, $target, $data) {
            $target->name = $data['name'];
            $target->email = $data['email'] ?? null;
            $target->is_active = (bool) ($data['is_active'] ?? false);

            if (array_key_exists('must_change_password', $data)) {
                $target->must_change_password = (bool) $data['must_change_password'];
            }

            if (! empty($data['password'])) {
                $target->password = Hash::make((string) $data['password']);
            }

            $target->save();

            if (! empty($data['role_code'])) {
                $this->syncPrimaryRole($target, (string) $data['role_code']);
            }

            $this->auditLogger->log(
                'iam',
                'user.update',
                'success',
                $actor,
                targetUser: $target,
                targetType: User::class,
                targetId: $target->id,
                meta: ['login_id' => $target->login_id],
            );

            return $target->fresh();
        });
    }

    public function delete(User $actor, User $target): void
    {
        $this->authorization->authorize($actor, 'iam.user.manage');
        $this->assertCanManageTarget($actor, $target);

        if ($actor->id === $target->id) {
            throw new InvalidArgumentException('自分自身は削除できません。');
        }

        $target->delete();

        $this->auditLogger->log(
            'iam',
            'user.delete',
            'success',
            $actor,
            targetUser: $target,
            targetType: User::class,
            targetId: $target->id,
            meta: ['login_id' => $target->login_id],
        );
    }

    /**
     * @return list<string>
     */
    public function assignableRoleCodes(User $actor, UserType $forType): array
    {
        return match ($forType) {
            UserType::Admin => $actor->user_type === UserType::Admin ? ['system_admin'] : [],
            UserType::Bp => $actor->user_type === UserType::Customer
                ? []
                : ['bp_owner', 'bp_sales', 'bp_support'],
            UserType::Customer => ['customer_owner', 'customer_member'],
        };
    }

    public function assertCanManageTarget(User $actor, User $target): void
    {
        if ($actor->user_type === UserType::Admin) {
            return;
        }

        if ($actor->user_type === UserType::Customer) {
            if ($target->user_type !== UserType::Customer
                || (int) $target->customer_id !== (int) $actor->customer_id) {
                abort(403, '自組織以外のユーザーは操作できません。');
            }

            return;
        }

        if ($actor->user_type !== UserType::Bp || $actor->businessPartner === null) {
            abort(403, 'ユーザー管理の権限がありません。');
        }

        $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        if ($target->user_type === UserType::Bp) {
            if (! in_array((int) $target->bp_id, $scope, true)) {
                abort(403, '配下外のBPユーザーは操作できません。');
            }

            return;
        }

        if ($target->user_type === UserType::Customer) {
            $target->loadMissing('customer');
            if ($target->customer === null || ! in_array((int) $target->customer->managing_bp_id, $scope, true)) {
                abort(403, '配下外のカスタマーユーザーは操作できません。');
            }

            return;
        }

        abort(403, '管理者ユーザーはBPから操作できません。');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCreateAllowed(User $actor, UserType $type, array $data): void
    {
        if ($actor->user_type === UserType::Customer) {
            if ($type !== UserType::Customer) {
                throw new InvalidArgumentException('カスタマーは自組織のカスタマーユーザーのみ作成できます。');
            }
            if ((int) ($data['customer_id'] ?? 0) !== (int) $actor->customer_id) {
                throw new InvalidArgumentException('自組織以外のカスタマーにはユーザーを作成できません。');
            }
        }

        if ($actor->user_type === UserType::Bp) {
            if ($type === UserType::Admin) {
                throw new InvalidArgumentException('BPは管理者ユーザーを作成できません。');
            }
            $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
            if ($type === UserType::Bp) {
                if (! in_array((int) $data['bp_id'], $scope, true)) {
                    throw new InvalidArgumentException('配下外のBPにはユーザーを作成できません。');
                }
            }
            if ($type === UserType::Customer) {
                $customer = Customer::query()->findOrFail((int) $data['customer_id']);
                if (! in_array((int) $customer->managing_bp_id, $scope, true)) {
                    throw new InvalidArgumentException('配下外のカスタマーにはユーザーを作成できません。');
                }
            }
        }

        if ($type === UserType::Bp) {
            BusinessPartner::query()->findOrFail((int) $data['bp_id']);
        }
        if ($type === UserType::Customer) {
            Customer::query()->findOrFail((int) $data['customer_id']);
        }

        $role = (string) ($data['role_code'] ?? '');
        if (! in_array($role, $this->assignableRoleCodes($actor, $type), true)) {
            throw new InvalidArgumentException('指定できないロールです。');
        }
    }

    private function assertActorCanManage(User $actor): void
    {
        if (! in_array($actor->user_type, [UserType::Admin, UserType::Bp, UserType::Customer], true)) {
            throw new InvalidArgumentException('ユーザー管理の操作主体が不正です。');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function loginIdTaken(UserType $type, string $loginId, array $data): bool
    {
        $query = User::withTrashed()->where('login_id', $loginId);

        return match ($type) {
            UserType::Admin => $query->where('user_type', UserType::Admin)->exists(),
            UserType::Bp => $query->where('user_type', UserType::Bp)
                ->where('bp_id', (int) $data['bp_id'])
                ->exists(),
            UserType::Customer => $query->where('user_type', UserType::Customer)
                ->where('customer_id', (int) $data['customer_id'])
                ->exists(),
        };
    }

    private function syncPrimaryRole(User $user, string $roleCode): void
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        DB::table('user_role')->where('user_id', $user->id)->delete();

        [$scope, $scopeId] = match ($user->user_type) {
            UserType::Admin => [RoleScope::System, null],
            UserType::Bp => [RoleScope::Bp, $user->bp_id],
            UserType::Customer => [RoleScope::Customer, $user->customer_id],
        };

        $this->rbac->assignRole($user, $role, $scope, $scopeId);
    }
}
