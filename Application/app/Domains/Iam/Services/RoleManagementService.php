<?php

namespace App\Domains\Iam\Services;

use App\Domains\Iam\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoleManagementService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, Role>
     */
    public function listRoles(): Collection
    {
        return Role::query()
            ->withCount('permissions')
            ->withCount('users')
            ->orderBy('scope')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $permissionCodes
     */
    public function create(User $actor, array $data, array $permissionCodes): Role
    {
        $this->authorization->authorize($actor, 'iam.role.manage');

        $code = $this->normalizeCode((string) $data['code']);
        if (Role::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException('このロールコードは既に使用されています。');
        }
        if (Role::isBuiltinCode($code)) {
            throw new InvalidArgumentException('組み込みロールと同じコードは使えません。');
        }

        $scope = RoleScope::from((string) $data['scope']);
        $permissionIds = $this->resolvePermissionIds($permissionCodes);

        return DB::transaction(function () use ($actor, $data, $code, $scope, $permissionIds) {
            $role = Role::query()->create([
                'code' => $code,
                'name' => (string) $data['name'],
                'scope' => $scope->value,
                'description' => $data['description'] ?? null,
            ]);

            $role->permissions()->sync($permissionIds);

            $this->auditLogger->log(
                'iam',
                'role.create',
                'success',
                $actor,
                targetType: Role::class,
                targetId: $role->id,
                meta: ['code' => $role->code, 'scope' => $role->scope],
            );

            return $role->load('permissions');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $permissionCodes
     */
    public function update(User $actor, Role $role, array $data, array $permissionCodes): Role
    {
        $this->authorization->authorize($actor, 'iam.role.manage');

        return DB::transaction(function () use ($actor, $role, $data, $permissionCodes) {
            $role->name = (string) $data['name'];
            $role->description = $data['description'] ?? null;

            if (! $role->isBuiltin()) {
                $scope = RoleScope::from((string) $data['scope']);
                $role->scope = $scope->value;
            }

            $role->save();

            if ($role->code === 'system_admin') {
                $permissionIds = Permission::query()->pluck('id');
            } else {
                $permissionIds = $this->resolvePermissionIds($permissionCodes);
            }

            $role->permissions()->sync($permissionIds);

            $this->auditLogger->log(
                'iam',
                'role.update',
                'success',
                $actor,
                targetType: Role::class,
                targetId: $role->id,
                meta: ['code' => $role->code],
            );

            return $role->fresh()->load('permissions');
        });
    }

    public function delete(User $actor, Role $role): void
    {
        $this->authorization->authorize($actor, 'iam.role.manage');

        if ($role->isBuiltin()) {
            throw new InvalidArgumentException('組み込みロールは削除できません。');
        }

        if ($role->users()->exists()) {
            throw new InvalidArgumentException('ユーザーに割り当て中のロールは削除できません。');
        }

        $code = $role->code;
        $id = $role->id;
        $role->delete();

        $this->auditLogger->log(
            'iam',
            'role.delete',
            'success',
            $actor,
            targetType: Role::class,
            targetId: $id,
            meta: ['code' => $code],
        );
    }

    /**
     * @return Collection<int, Collection<int, Permission>>
     */
    public function permissionsGroupedByResource(): Collection
    {
        return Permission::query()
            ->orderBy('resource')
            ->orderBy('code')
            ->get()
            ->groupBy(fn (Permission $permission) => $permission->resource ?: 'other');
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            throw new InvalidArgumentException('ロールコードは英小文字・数字・アンダースコア（先頭は英字）で指定してください。');
        }

        return $code;
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return Collection<int, int>
     */
    private function resolvePermissionIds(array $permissionCodes): Collection
    {
        $codes = array_values(array_unique(array_filter($permissionCodes)));
        if ($codes === []) {
            throw new InvalidArgumentException('権限を1つ以上選択してください。');
        }

        $ids = Permission::query()->whereIn('code', $codes)->pluck('id');
        if ($ids->count() !== count($codes)) {
            throw new InvalidArgumentException('存在しない権限コードが含まれています。');
        }

        return $ids;
    }
}
