<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Billing\Services\BillingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
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
            'canEditPaidAmount' => false,
        ]);
    }

    public function show(Request $request, Invoice $invoice, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.view');
        $billing->assertVisible($actor, $invoice);

        return view('admin.invoices.show', [
            'invoice' => $invoice->load(['lines', 'customer', 'owningBp', 'issuerBp', 'contract', 'kickbacks']),
            'routePrefix' => 'bp',
            'canManage' => $authorization->can($actor, 'invoice.manage'),
            'canEditPaidAmount' => false,
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $billing->markPaid($request->user('bp'), $invoice, (int) $validated['paid_amount']);
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

        return back()->with('status', '請求を取下げました。');
    }
}
