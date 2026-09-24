<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ApplicationController extends Controller
{
    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.approve'), 403);
        abort_unless($actor->bp_id, 403);

        $applications = Application::query()
            ->with(['contract', 'fromBp', 'toBp'])
            ->where('to_bp_id', $actor->bp_id)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20);

        return view('admin.applications.index', [
            'applications' => $applications,
            'routePrefix' => 'bp',
        ]);
    }

    public function decide(Request $request, Application $application, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            if ($application->type->value === 'price_approval') {
                $service->decidePriceApproval($request->user('bp'), $application, $validated['approve'], $validated['note'] ?? null);
            } else {
                $service->decidePriceChange($request->user('bp'), $application, $validated['approve'], $validated['note'] ?? null);
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['application' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', ['contract' => $application->contract_id, 'tab' => 'items'])
            ->with('status', '申請を処理しました。');
    }
}
