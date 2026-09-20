<?php

namespace App\Domains\Iam\Services;

use App\Domains\Iam\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RbacService
{
    public function assignRole(
        User $user,
        Role|string $role,
        RoleScope $scopeType = RoleScope::System,
        ?int $scopeId = null,
    ): void {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->where('code', $role)->firstOrFail();

        if ($scopeType === RoleScope::System) {
            $scopeId = null;
        }

        $exists = DB::table('user_role')
            ->where('user_id', $user->id)
            ->where('role_id', $roleModel->id)
            ->where('scope_type', $scopeType->value)
            ->where(function ($query) use ($scopeId) {
                if ($scopeId === null) {
                    $query->whereNull('scope_id');
                } else {
                    $query->where('scope_id', $scopeId);
                }
            })
            ->exists();

        if ($exists) {
            return;
        }

        $user->roles()->attach($roleModel->id, [
            'scope_type' => $scopeType->value,
            'scope_id' => $scopeId,
        ]);
    }

    public function revokeRole(
        User $user,
        Role|string $role,
        RoleScope $scopeType = RoleScope::System,
        ?int $scopeId = null,
    ): void {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->where('code', $role)->firstOrFail();

        $query = DB::table('user_role')
            ->where('user_id', $user->id)
            ->where('role_id', $roleModel->id)
            ->where('scope_type', $scopeType->value);

        if ($scopeId === null) {
            $query->whereNull('scope_id');
        } else {
            $query->where('scope_id', $scopeId);
        }

        $query->delete();
    }

    public function permissionCodes(User $user): Collection
    {
        return Permission::query()
            ->select('permissions.code')
            ->join('role_permission', 'role_permission.permission_id', '=', 'permissions.id')
            ->join('user_role', 'user_role.role_id', '=', 'role_permission.role_id')
            ->where('user_role.user_id', $user->id)
            ->distinct()
            ->pluck('permissions.code');
    }

    public function hasPermission(User $user, string $permissionCode): bool
    {
        return $this->permissionCodes($user)->contains($permissionCode);
    }

    public function hasAnyPermission(User $user, array $permissionCodes): bool
    {
        $owned = $this->permissionCodes($user);

        foreach ($permissionCodes as $code) {
            if ($owned->contains($code)) {
                return true;
            }
        }

        return false;
    }
}
