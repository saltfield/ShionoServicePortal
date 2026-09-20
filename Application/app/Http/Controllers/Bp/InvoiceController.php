<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class InvoiceController extends Controller
{
    public function index(Request $request, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.view');

        return view('admin.invoices.index', [
            'invoices' => $billing->visibleQuery($actor)->latest('id')->paginate(20),
            'routePrefix' => 'bp',
            'canManage' => $authorization->can($actor, 'invoice.manage'),
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.manage');
        $scope = $hierarchy->descendantIdsIncludingSelf($actor->businessPartner);

        return view('admin.invoices.create', [
            'routePrefix' => 'bp',
            'contracts' => Contract::query()
                ->with(['customer', 'owningBp'])
                ->where('status', ContractStatus::Activated)
                ->whereIn('owning_bp_id', $scope)
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
            'defaultMonth' => now()->format('Ym'),
        ]);
    }

    public function store(Request $request, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'billing_year_month' => ['required', 'string', 'regex:/^\d{6}$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $contract = Contract::query()->findOrFail($validated['contract_id']);

        try {
            $invoice = $billing->issueFromContract(
                $request->user('bp'),
                $contract,
                $validated['billing_year_month'],
                $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.invoices.show', $invoice)
            ->with('status', '請求を発行しました。');
    }

    public function show(Request $request, Invoice $invoice, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.view');
        $billing->assertVisible($actor, $invoice);

        return view('admin.invoices.show', [
            'invoice' => $invoice->load(['lines', 'customer', 'owningBp', 'contract']),
            'routePrefix' => 'bp',
            'canManage' => $authorization->can($actor, 'invoice.manage'),
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $billing->markPaid($request->user('bp'), $invoice);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return back()->with('status', '入金済に更新しました。');
    }

    public function cancel(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $billing->cancel($request->user('bp'), $invoice);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return back()->with('status', '請求を取消しました。');
    }
}
