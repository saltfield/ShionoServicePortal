<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\UserManagementService;
use App\Http\Controllers\Concerns\ConfirmsUserDeletion;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class UserController extends Controller
{
    use ConfirmsUserDeletion;

    public function index(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $scope = $hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        $tab = $request->input('tab', UserType::Bp->value);
        if (! in_array($tab, [UserType::Bp->value, UserType::Customer->value], true)) {
            $tab = UserType::Bp->value;
        }
        $userType = UserType::from($tab);

        $users = User::query()
            ->with(['businessPartner', 'customer.managingBp', 'roles'])
            ->where('user_type', $userType)
            ->when($userType === UserType::Bp, fn ($q) => $q->whereIn('bp_id', $scope))
            ->when($userType === UserType::Customer, function ($q) use ($scope) {
                $q->whereHas('customer', fn ($c) => $c->whereIn('managing_bp_id', $scope));
            })
            ->orderBy('login_id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'tab' => $tab,
            'counts' => [
                UserType::Bp->value => User::query()->where('user_type', UserType::Bp)->whereIn('bp_id', $scope)->count(),
                UserType::Customer->value => User::query()->where('user_type', UserType::Customer)
                    ->whereHas('customer', fn ($c) => $c->whereIn('managing_bp_id', $scope))->count(),
            ],
            'managingPartners' => collect(),
            'filters' => [
                'bpn' => '',
                'bp_name' => '',
                'cn' => '',
                'cn_name' => '',
                'managing_bp_id' => null,
            ],
            'routePrefix' => 'bp',
            'hideAdminTab' => true,
            'hidePrivilegeActions' => true,
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, UserManagementService $users, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $type = UserType::from($request->input('type', UserType::Bp->value));
        if ($type === UserType::Admin) {
            $type = UserType::Bp;
        }
        $scope = $hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        return view('admin.users.create', [
            'routePrefix' => 'bp',
            'userType' => $type,
            'roles' => $users->assignableRoleCodes($actor, $type),
            'businessPartners' => BusinessPartner::query()->whereIn('id', $scope)->orderBy('code')->get(['id', 'code', 'name']),
            'customers' => Customer::query()->whereIn('managing_bp_id', $scope)->orderBy('code')->get(['id', 'code', 'name', 'managing_bp_id']),
        ]);
    }

    public function store(Request $request, UserManagementService $users): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: true);
        if (($validated['user_type'] ?? '') === UserType::Admin->value) {
            throw ValidationException::withMessages(['user_type' => '管理者ユーザーは作成できません。']);
        }

        try {
            $user = $users->create($request->user('bp'), $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['login_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.users.index', ['tab' => $user->user_type->value])
            ->with('status', "{$user->login_id} を作成しました。");
    }

    public function edit(Request $request, User $user, AuthorizationService $authorization, UserManagementService $users): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $users->assertCanManageTarget($actor, $user);
        $user->load('roles');

        return view('admin.users.edit', [
            'routePrefix' => 'bp',
            'managedUser' => $user,
            'roles' => $users->assignableRoleCodes($actor, $user->user_type),
            'deleteConfirmationCode' => $this->issueUserDeleteConfirmationCode($user),
        ]);
    }

    public function update(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: false, target: $user);

        try {
            $users->update($request->user('bp'), $user, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.users.index', ['tab' => $user->user_type->value])
            ->with('status', 'ユーザーを更新しました。');
    }

    public function destroy(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $this->assertUserDeleteConfirmation($request, $user);
        $tab = $user->user_type->value;

        try {
            $users->delete($request->user('bp'), $user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.users.index', ['tab' => $tab])
            ->with('status', 'ユーザーを削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUser(Request $request, bool $creating, ?User $target = null): array
    {
        $type = $creating
            ? UserType::from($request->input('user_type', UserType::Bp->value))
            : $target->user_type;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'role_code' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'must_change_password' => ['nullable', 'boolean'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ];

        if ($creating) {
            $rules['user_type'] = ['required', Rule::in([UserType::Bp->value, UserType::Customer->value])];
            $rules['login_id'] = ['required', 'string', 'max:64'];
            if ($type === UserType::Bp) {
                $rules['bp_id'] = ['required', 'integer', 'exists:business_partners,id'];
            }
            if ($type === UserType::Customer) {
                $rules['customer_id'] = ['required', 'integer', 'exists:customers,id'];
            }
        }

        $validated = $request->validate($rules);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['must_change_password'] = $request->boolean('must_change_password', $creating);
        if ($creating) {
            $validated['user_type'] = $type->value;
            $validated['login_id'] = IdentifierNormalizer::normalize($validated['login_id']);
        }

        return $validated;
    }
}
