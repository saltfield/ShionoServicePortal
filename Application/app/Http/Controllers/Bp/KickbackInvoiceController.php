<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Billing\Services\KickbackService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\KickbackInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class KickbackInvoiceController extends Controller
{
    public function index(Request $request, KickbackService $kickbacks, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.view');

        return view('admin.kickbacks.index', [
            'invoices' => $kickbacks->visibleQuery($actor)->latest('id')->paginate(20),
            'routePrefix' => 'bp',
            'canManage' => $authorization->can($actor, 'invoice.manage'),
        ]);
    }

    public function show(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'invoice.view');
        $kickbacks->assertVisible($actor, $kickback);

        return view('admin.kickbacks.show', [
            'invoice' => $kickback->load(['lines', 'contract', 'fromBp', 'toBp']),
            'routePrefix' => 'bp',
            'canManage' => $authorization->can($actor, 'invoice.manage'),
        ]);
    }

    public function markPaid(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        try {
            $kickbacks->markPaid($request->user('bp'), $kickback);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', '入金済に更新しました。');
    }

    public function withdraw(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        try {
            $kickbacks->withdraw($request->user('bp'), $kickback);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', 'キックバックを取下げました。');
    }
}
