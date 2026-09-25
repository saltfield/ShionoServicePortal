<?php

namespace App\Http\Controllers\Admin;

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
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.view');

        return view('admin.invoices.index', [
            'invoices' => $billing->visibleQuery($actor)->latest('id')->paginate(20),
            'routePrefix' => 'admin',
            'canManage' => true,
            'canEditPaidAmount' => true,
        ]);
    }

    public function show(Request $request, Invoice $invoice, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.view');
        $billing->assertVisible($actor, $invoice);

        return view('admin.invoices.show', [
            'invoice' => $invoice->load(['lines', 'customer', 'owningBp', 'issuerBp', 'contract', 'kickbacks']),
            'routePrefix' => 'admin',
            'canManage' => true,
            'canEditPaidAmount' => true,
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $billing->markPaid($request->user('admin'), $invoice, (int) $validated['paid_amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return back()->with('status', '入金済に更新しました。');
    }

    public function updatePaidAmount(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $billing->updatePaidAmount($request->user('admin'), $invoice, (int) $validated['paid_amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return back()->with('status', '入金金額を更新し、キックバックを再計算しました。');
    }

    public function cancel(Request $request, Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $billing->cancel($request->user('admin'), $invoice);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return back()->with('status', '請求を取下げました。');
    }
}
