<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Support\Services\EntityNoteService;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait ManagesEntityNotes
{
    protected function upsertSharedEntityNote(
        Request $request,
        Model $subject,
        User $actor,
        EntityNoteService $notes,
        string $redirectRoute,
        array $redirectParams = [],
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:'.EntityNoteService::MAX_BODY_LENGTH],
        ]);

        try {
            $notes->upsertShared($actor, $subject, $validated['body'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->route($redirectRoute, array_merge($redirectParams, ['tab' => 'notes']))
            ->with('status', '共有備考を保存しました。');
    }

    protected function upsertOrganizationEntityNote(
        Request $request,
        Model $subject,
        User $actor,
        EntityNoteService $notes,
        string $redirectRoute,
        array $redirectParams = [],
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:'.EntityNoteService::MAX_BODY_LENGTH],
        ]);

        try {
            $notes->upsertOrganization($actor, $subject, $validated['body'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->route($redirectRoute, array_merge($redirectParams, ['tab' => 'notes']))
            ->with('status', '組織内備考を保存しました。');
    }

    protected function upsertSharedContractNote(
        Request $request,
        Contract $contract,
        User $actor,
        string $routePrefix,
        EntityNoteService $notes,
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:'.EntityNoteService::MAX_BODY_LENGTH],
        ]);

        try {
            $notes->upsertShared($actor, $contract, $validated['body'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return $this->redirectToContractShow($request, $routePrefix, $contract, 'notes', '共有備考を保存しました。');
    }

    protected function upsertOrganizationContractNote(
        Request $request,
        Contract $contract,
        User $actor,
        string $routePrefix,
        EntityNoteService $notes,
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:'.EntityNoteService::MAX_BODY_LENGTH],
        ]);

        try {
            $notes->upsertOrganization($actor, $contract, $validated['body'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return $this->redirectToContractShow($request, $routePrefix, $contract, 'notes', '組織内備考を保存しました。');
    }
}
