<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\UserManagementService;
use App\Http\Controllers\Concerns\ConfirmsUserDeletion;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class UserController extends Controller
{
    use ConfirmsUserDeletion;

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $actor = $request->user('customer');
        $authorization->authorize($actor, 'iam.user.manage');

        $users = User::query()
            ->with('roles')
            ->where('user_type', UserType::Customer)
            ->where('customer_id', $actor->customer_id)
            ->orderBy('login_id')
            ->paginate(20);

        return view('customer.users.index', [
            'users' => $users,
            'routePrefix' => 'customer',
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, UserManagementService $users): View
    {
        $actor = $request->user('customer');
        $authorization->authorize($actor, 'iam.user.manage');
        $actor->loadMissing('customer');

        return view('customer.users.create', [
            'routePrefix' => 'customer',
            'roles' => $users->assignableRoleCodes($actor, UserType::Customer),
            'customer' => $actor->customer,
        ]);
    }

    public function store(Request $request, UserManagementService $users): RedirectResponse
    {
        $actor = $request->user('customer');
        $validated = $this->validatedUser($request, creating: true);
        $validated['user_type'] = UserType::Customer->value;
        $validated['customer_id'] = (int) $actor->customer_id;

        try {
            $user = $users->create($actor, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['login_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.users.index')
            ->with('status', "{$user->login_id} を作成しました。");
    }

    public function edit(Request $request, User $user, AuthorizationService $authorization, UserManagementService $users): View
    {
        $actor = $request->user('customer');
        $authorization->authorize($actor, 'iam.user.manage');
        $users->assertCanManageTarget($actor, $user);
        $user->load('roles');

        return view('customer.users.edit', [
            'routePrefix' => 'customer',
            'managedUser' => $user,
            'roles' => $users->assignableRoleCodes($actor, UserType::Customer),
            'deleteConfirmationCode' => $this->issueUserDeleteConfirmationCode($user),
        ]);
    }

    public function update(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $validated = $this->validatedUser($request, creating: false, target: $user);

        try {
            $users->update($request->user('customer'), $user, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.users.index')
            ->with('status', 'ユーザーを更新しました。');
    }

    public function destroy(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $this->assertUserDeleteConfirmation($request, $user);

        try {
            $users->delete($request->user('customer'), $user);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['user' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.users.index')
            ->with('status', 'ユーザーを削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUser(Request $request, bool $creating, ?User $target = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'role_code' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'must_change_password' => ['nullable', 'boolean'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ];

        if ($creating) {
            $rules['login_id'] = ['required', 'string', 'max:64'];
        }

        $validated = $request->validate($rules);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['must_change_password'] = $request->boolean('must_change_password', $creating);
        if ($creating) {
            $validated['login_id'] = IdentifierNormalizer::normalize($validated['login_id']);
        }

        return $validated;
    }
}
