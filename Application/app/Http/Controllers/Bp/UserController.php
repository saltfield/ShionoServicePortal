<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\UserManagementService;
use App\Domains\Notification\Services\NotificationDispatcher;
use App\Http\Controllers\Concerns\ConfirmsUserDeletion;
use App\Http\Controllers\Concerns\ManagesUserRoleAssignments;
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
    use ManagesUserRoleAssignments;

    protected function userManagementGuard(): string
    {
        return 'bp';
    }

    public function index(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $users = User::query()
            ->with(['businessPartner', 'roles'])
            ->where('user_type', UserType::Bp)
            ->where('bp_id', $actorBp->id)
            ->orderBy('login_id')
            ->paginate(20);

        return view('admin.users.index', [
            'users' => $users,
            'tab' => UserType::Bp->value,
            'counts' => [
                UserType::Bp->value => $users->total(),
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
            'selfBpOnly' => true,
            'actorBp' => $actorBp,
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, UserManagementService $users, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $type = UserType::from($request->input('type', UserType::Bp->value));
        if ($type === UserType::Admin) {
            $type = UserType::Bp;
        }

        $scope = $hierarchy->descendantIdsIncludingSelf($actorBp);
        $returnBpId = $request->filled('return_bp_id') ? (int) $request->input('return_bp_id') : null;
        if ($returnBpId !== null && ! in_array($returnBpId, $scope, true)) {
            abort(403);
        }

        $selectedBpId = $type === UserType::Bp
            ? ($returnBpId ?? (int) $actorBp->id)
            : null;
        if ($selectedBpId !== null && ! in_array($selectedBpId, $scope, true)) {
            abort(403);
        }

        $selectedCustomerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $returnCustomerId = $request->filled('return_customer_id') ? (int) $request->input('return_customer_id') : $selectedCustomerId;

        return view('admin.users.create', [
            'routePrefix' => 'bp',
            'userType' => $type,
            'roles' => $users->assignableRoles($actor, $type),
            'businessPartners' => $selectedBpId
                ? BusinessPartner::query()->whereKey($selectedBpId)->get(['id', 'code', 'name'])
                : collect(),
            'customers' => Customer::query()->whereIn('managing_bp_id', $scope)->orderBy('code')->get(['id', 'code', 'name', 'managing_bp_id']),
            'selectedCustomerId' => $selectedCustomerId,
            'returnCustomerId' => $returnCustomerId,
            'returnBpId' => $returnBpId,
            'lockOrganization' => true,
            'lockUserType' => true,
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

        return $this->redirectAfterUserMutation($request, $user->user_type->value, "{$user->login_id} を作成しました。");
    }

    public function edit(Request $request, User $user, AuthorizationService $authorization, UserManagementService $users, NotificationDispatcher $notifications): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'iam.user.manage');
        $users->assertCanManageTarget($actor, $user);
        $user->load(['roles.permissions', 'businessPartner', 'customer']);

        $returnCustomerId = $request->filled('return_customer_id')
            ? (int) $request->input('return_customer_id')
            : ($user->user_type === UserType::Customer ? $user->customer_id : null);
        $returnBpId = $request->filled('return_bp_id')
            ? (int) $request->input('return_bp_id')
            : null;

        $assignedCodes = $user->roles->pluck('code')->all();
        $assignableRoles = $users->assignableRoles($actor, $user->user_type)
            ->reject(fn ($role) => in_array($role->code, $assignedCodes, true))
            ->values();

        return view('admin.users.edit', [
            'routePrefix' => 'bp',
            'managedUser' => $user,
            'roles' => $users->assignableRoles($actor, $user->user_type),
            'assignableRoles' => $assignableRoles,
            'scopeLabel' => $users->scopeLabelFor($user),
            'deleteConfirmationCode' => $this->issueUserDeleteConfirmationCode($user),
            'returnCustomerId' => $returnCustomerId,
            'returnBpId' => $returnBpId,
            'notificationPreferences' => $notifications->preferencesFor($user),
        ]);
    }

    public function update(Request $request, User $user, UserManagementService $users, NotificationDispatcher $notifications): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: false, target: $user);

        try {
            $users->update($request->user('bp'), $user, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        if ($request->boolean('notification_preferences_present')) {
            $raw = $request->input('preferences', []);
            $notifications->syncPreferences(
                $user->fresh(),
                $notifications->enabledMapFromRequest(is_array($raw) ? $raw : [])
            );
        }

        $status = 'ユーザーを更新しました。';
        $fresh = $user->fresh();
        if ($fresh->must_change_password && ! filled($fresh->email)) {
            $status .= ' パスワード強制変更は有効ですが、メール未設定のため通知は送信していません。';
        }

        return $this->redirectAfterUserMutation($request, $user->user_type->value, $status);
    }

    public function destroy(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $this->assertUserDeleteConfirmation($request, $user);
        $tab = $user->user_type->value;
        $returnCustomerId = $request->filled('return_customer_id')
            ? (int) $request->input('return_customer_id')
            : ($user->user_type === UserType::Customer ? $user->customer_id : null);
        $returnBpId = $request->filled('return_bp_id') ? (int) $request->input('return_bp_id') : null;

        try {
            $users->delete($request->user('bp'), $user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return $this->redirectAfterUserMutation($request, $tab, 'ユーザーを削除しました。', $returnCustomerId, $returnBpId);
    }

    private function redirectAfterUserMutation(
        Request $request,
        string $tab,
        string $status,
        ?int $returnCustomerId = null,
        ?int $returnBpId = null,
    ): RedirectResponse {
        $returnCustomerId = $returnCustomerId
            ?? ($request->filled('return_customer_id') ? (int) $request->input('return_customer_id') : null);
        $returnBpId = $returnBpId
            ?? ($request->filled('return_bp_id') ? (int) $request->input('return_bp_id') : null);

        if ($returnCustomerId) {
            return redirect()
                ->route('bp.customers.show', ['customer' => $returnCustomerId, 'tab' => 'users'])
                ->with('status', $status);
        }

        if (! $returnCustomerId && $tab === UserType::Customer->value && $request->filled('customer_id')) {
            return redirect()
                ->route('bp.customers.show', ['customer' => (int) $request->input('customer_id'), 'tab' => 'users'])
                ->with('status', $status);
        }

        if ($returnBpId) {
            return redirect()
                ->route('bp.business-partners.show', ['businessPartner' => $returnBpId, 'tab' => 'users'])
                ->with('status', $status);
        }

        return redirect()
            ->route('bp.users.index')
            ->with('status', $status);
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
            'is_active' => ['nullable', 'boolean'],
            'must_change_password' => ['nullable', 'boolean'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ];

        if ($creating) {
            $rules['role_code'] = ['required', 'string'];
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
        $validated['must_change_password'] = $request->boolean('must_change_password');
        if ($creating) {
            $validated['user_type'] = $type->value;
            $validated['login_id'] = IdentifierNormalizer::normalize($validated['login_id']);
        }

        return $validated;
    }
}
