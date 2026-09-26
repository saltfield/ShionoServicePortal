<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RoleManagementService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class RoleController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization, RoleManagementService $roles): View
    {
        $authorization->authorize($request->user('admin'), 'iam.role.manage');

        return view('admin.roles.index', [
            'roles' => $roles->listRoles(),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, RoleManagementService $roles): View
    {
        $authorization->authorize($request->user('admin'), 'iam.role.manage');

        return view('admin.roles.create', [
            'permissionGroups' => $roles->permissionsGroupedByResource(),
            'scopes' => RoleScope::cases(),
        ]);
    }

    public function store(Request $request, RoleManagementService $roles): RedirectResponse
    {
        $validated = $this->validatedRole($request, creating: true);

        try {
            $role = $roles->create(
                $request->user('admin'),
                $validated,
                $validated['permission_codes'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('status', "ロール {$role->code} を作成しました。");
    }

    public function edit(Request $request, Role $role, AuthorizationService $authorization, RoleManagementService $roles): View
    {
        $authorization->authorize($request->user('admin'), 'iam.role.manage');
        $role->load('permissions');

        return view('admin.roles.edit', [
            'managedRole' => $role,
            'permissionGroups' => $roles->permissionsGroupedByResource(),
            'scopes' => RoleScope::cases(),
            'selectedPermissionCodes' => $role->permissions->pluck('code')->all(),
        ]);
    }

    public function update(Request $request, Role $role, RoleManagementService $roles): RedirectResponse
    {
        $validated = $this->validatedRole($request, creating: false, role: $role);

        try {
            $roles->update(
                $request->user('admin'),
                $role,
                $validated,
                $validated['permission_codes'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.edit', $role)
            ->with('status', 'ロールを更新しました。');
    }

    public function destroy(Request $request, Role $role, RoleManagementService $roles): RedirectResponse
    {
        try {
            $roles->delete($request->user('admin'), $role);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.index')
            ->with('status', 'ロールを削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedRole(Request $request, bool $creating, ?Role $role = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permission_codes' => ['nullable', 'array'],
            'permission_codes.*' => ['string', 'exists:permissions,code'],
        ];

        if ($creating) {
            $rules['code'] = ['required', 'string', 'max:64'];
            $rules['scope'] = ['required', Rule::enum(RoleScope::class)];
            $rules['permission_codes'] = ['required', 'array', 'min:1'];
        } elseif ($role !== null && ! $role->isBuiltin()) {
            $rules['scope'] = ['required', Rule::enum(RoleScope::class)];
        }

        if ($role !== null && $role->code === 'system_admin') {
            unset($rules['permission_codes'], $rules['permission_codes.*']);
        }

        $validated = $request->validate($rules);
        $validated['permission_codes'] = array_values($request->input('permission_codes', []));

        if ($creating || ($role !== null && $role->code !== 'system_admin')) {
            if ($validated['permission_codes'] === []) {
                throw ValidationException::withMessages([
                    'permission_codes' => '権限を1つ以上選択してください。',
                ]);
            }
        }

        return $validated;
    }
}
