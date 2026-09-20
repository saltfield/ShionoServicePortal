<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AdminPrivilegeService;
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

class UserPrivilegeController extends Controller
{
    use ConfirmsUserDeletion;

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'iam.user.manage');

        $tab = $request->input('tab', UserType::Admin->value);
        if (! in_array($tab, [UserType::Admin->value, UserType::Bp->value, UserType::Customer->value], true)) {
            $tab = UserType::Admin->value;
        }

        $userType = UserType::from($tab);
        $bpn = trim((string) $request->input('bpn', ''));
        $bpName = trim((string) $request->input('bp_name', ''));
        $cn = trim((string) $request->input('cn', ''));
        $cnName = trim((string) $request->input('cn_name', ''));
        $managingBpId = $request->filled('managing_bp_id') ? (int) $request->input('managing_bp_id') : null;

        $usersQuery = User::query()
            ->with(['businessPartner', 'customer.managingBp', 'roles'])
            ->where('user_type', $userType)
            ->when($userType === UserType::Bp && $bpn !== '', function ($query) use ($bpn) {
                $normalized = IdentifierNormalizer::normalize($bpn);
                $query->whereHas('businessPartner', fn ($bp) => $bp->where('code', 'like', "%{$normalized}%"));
            })
            ->when($userType === UserType::Bp && $bpName !== '', function ($query) use ($bpName) {
                $query->whereHas('businessPartner', fn ($bp) => $bp->where('name', 'like', "%{$bpName}%"));
            })
            ->when($userType === UserType::Customer, function ($query) use ($managingBpId, $cn, $cnName) {
                if ($managingBpId === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereHas('customer', function ($customer) use ($managingBpId, $cn, $cnName) {
                    $customer->where('managing_bp_id', $managingBpId);

                    if ($cn !== '') {
                        $normalized = IdentifierNormalizer::normalize($cn);
                        $customer->where('code', 'like', "%{$normalized}%");
                    }

                    if ($cnName !== '') {
                        $customer->where('name', 'like', "%{$cnName}%");
                    }
                });
            })
            ->orderBy('login_id');

        $users = $usersQuery->paginate(20)->withQueryString();

        $counts = [
            UserType::Admin->value => User::query()->where('user_type', UserType::Admin)->count(),
            UserType::Bp->value => User::query()->where('user_type', UserType::Bp)->count(),
            UserType::Customer->value => User::query()->where('user_type', UserType::Customer)->count(),
        ];

        $managingPartners = $userType === UserType::Customer
            ? BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(['id', 'code', 'name'])
            : collect();

        return view('admin.users.index', [
            'users' => $users,
            'tab' => $tab,
            'counts' => $counts,
            'managingPartners' => $managingPartners,
            'filters' => [
                'bpn' => $userType === UserType::Bp ? $bpn : '',
                'bp_name' => $userType === UserType::Bp ? $bpName : '',
                'cn' => $userType === UserType::Customer ? $cn : '',
                'cn_name' => $userType === UserType::Customer ? $cnName : '',
                'managing_bp_id' => $userType === UserType::Customer ? $managingBpId : null,
            ],
            'routePrefix' => 'admin',
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, UserManagementService $users): View
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'iam.user.manage');
        $type = UserType::from($request->input('type', UserType::Admin->value));

        return view('admin.users.create', [
            'routePrefix' => 'admin',
            'userType' => $type,
            'roles' => $users->assignableRoleCodes($actor, $type),
            'businessPartners' => BusinessPartner::query()->orderBy('code')->get(['id', 'code', 'name']),
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name', 'managing_bp_id']),
        ]);
    }

    public function store(Request $request, UserManagementService $users): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: true);

        try {
            $user = $users->create($request->user('admin'), $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['login_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.index', ['tab' => $user->user_type->value])
            ->with('status', "{$user->login_id} を作成しました。");
    }

    public function edit(Request $request, User $user, AuthorizationService $authorization, UserManagementService $users): View
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'iam.user.manage');
        $users->assertCanManageTarget($actor, $user);
        $user->load('roles');

        return view('admin.users.edit', [
            'routePrefix' => 'admin',
            'managedUser' => $user,
            'roles' => $users->assignableRoleCodes($actor, $user->user_type),
            'deleteConfirmationCode' => $this->issueUserDeleteConfirmationCode($user),
        ]);
    }

    public function update(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: false, target: $user);

        try {
            $users->update($request->user('admin'), $user, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.index', ['tab' => $user->user_type->value])
            ->with('status', 'ユーザーを更新しました。');
    }

    public function destroy(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $this->assertUserDeleteConfirmation($request, $user);
        $tab = $user->user_type->value;

        try {
            $users->delete($request->user('admin'), $user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.users.index', ['tab' => $tab])
            ->with('status', 'ユーザーを削除しました。');
    }

    public function forcePassword(Request $request, User $user, AdminPrivilegeService $privileges): RedirectResponse
    {
        $privileges->forcePasswordChange($request->user('admin'), $user);

        return back()->with('status', "{$user->login_id} にパスワード強制変更を設定しました。");
    }

    public function forceDisableTwoFactor(Request $request, User $user, AdminPrivilegeService $privileges): RedirectResponse
    {
        $privileges->forceDisableTwoFactor($request->user('admin'), $user);

        return back()->with('status', "{$user->login_id} の2FAを緊急スキップに設定しました。");
    }

    public function clearForceDisableTwoFactor(Request $request, User $user, AdminPrivilegeService $privileges): RedirectResponse
    {
        $privileges->clearForceDisableTwoFactor($request->user('admin'), $user);

        return back()->with('status', "{$user->login_id} の2FA緊急スキップを解除しました。");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUser(Request $request, bool $creating, ?User $target = null): array
    {
        $type = $creating
            ? UserType::from($request->input('user_type', UserType::Admin->value))
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
            $rules['user_type'] = ['required', Rule::enum(UserType::class)];
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
