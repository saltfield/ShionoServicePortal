<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Iam\Services\UserManagementService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait ManagesUserRoleAssignments
{
    public function assignRole(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $actor = $request->user($this->userManagementGuard());
        $validated = $request->validate([
            'role_code' => ['required', 'string'],
        ]);

        try {
            $users->assignRoleToUser($actor, $user, $validated['role_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['role_code' => $exception->getMessage()]);
        }

        return back()->with('status', 'ロールを割り当てました。');
    }

    public function revokeRole(Request $request, User $user, UserManagementService $users): RedirectResponse
    {
        $actor = $request->user($this->userManagementGuard());
        $validated = $request->validate([
            'role_code' => ['required', 'string'],
        ]);

        try {
            $users->revokeRoleFromUser($actor, $user, $validated['role_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['role_code' => $exception->getMessage()]);
        }

        return back()->with('status', 'ロールの割り当てを解除しました。');
    }

    abstract protected function userManagementGuard(): string;
}
