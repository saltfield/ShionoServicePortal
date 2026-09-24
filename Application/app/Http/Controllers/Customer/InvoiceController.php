<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Billing\Services\BillingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('customer');
        $authorization->authorize($actor, 'invoice.view');

        return view('admin.invoices.index', [
            'invoices' => $billing->visibleQuery($actor)->latest('id')->paginate(20),
            'routePrefix' => 'customer',
            'canManage' => false,
        ]);
    }

    public function show(Request $request, Invoice $invoice, BillingService $billing, AuthorizationService $authorization): View
    {
        $actor = $request->user('customer');
        $authorization->authorize($actor, 'invoice.view');
        $billing->assertVisible($actor, $invoice);

        return view('admin.invoices.show', [
            'invoice' => $invoice->load(['lines', 'customer', 'owningBp', 'issuerBp', 'contract']),
            'routePrefix' => 'customer',
            'canManage' => false,
        ]);
    }
}
