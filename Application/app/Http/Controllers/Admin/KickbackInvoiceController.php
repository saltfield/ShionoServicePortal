<?php

namespace App\Http\Controllers\Admin;

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
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.view');

        return view('admin.kickbacks.index', [
            'invoices' => $kickbacks->visibleQuery($actor)->latest('id')->paginate(20),
            'routePrefix' => 'admin',
            'canManage' => true,
            'canAdjustAmounts' => true,
            'canEditPaidAmount' => true,
        ]);
    }

    public function show(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks, AuthorizationService $authorization): View
    {
        $actor = $request->user('admin');
        $authorization->authorize($actor, 'invoice.view');
        $kickbacks->assertVisible($actor, $kickback);

        return view('admin.kickbacks.show', [
            'invoice' => $kickback->load(['lines', 'contract', 'fromBp', 'toBp', 'sourceInvoice']),
            'routePrefix' => 'admin',
            'canManage' => true,
            'canAdjustAmounts' => true,
            'canEditPaidAmount' => true,
        ]);
    }

    public function markPaid(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $kickbacks->markPaid($request->user('admin'), $kickback, (int) $validated['paid_amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', '入金済に更新しました。');
    }

    public function updatePaidAmount(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        $validated = $request->validate([
            'paid_amount' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $kickbacks->updatePaidAmount($request->user('admin'), $kickback, (int) $validated['paid_amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', '入金金額を更新しました。');
    }

    public function withdraw(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        try {
            $kickbacks->withdraw($request->user('admin'), $kickback);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', 'キックバックを取下げました。');
    }

    public function adjustAmounts(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        $validated = $request->validate([
            'adjustment_amount' => ['required', 'integer'],
        ]);

        try {
            $kickbacks->adjustAmounts(
                $request->user('admin'),
                $kickback,
                (int) $validated['adjustment_amount'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        $amount = (int) $validated['adjustment_amount'];

        return back()->with(
            'status',
            $amount === 0 ? '端数調整明細を削除しました。' : '端数調整明細を登録しました。'
        );
    }

    public function regenerate(Request $request, KickbackInvoice $kickback, KickbackService $kickbacks): RedirectResponse
    {
        $kickback->load('sourceInvoice');
        if (! $kickback->sourceInvoice) {
            throw ValidationException::withMessages(['kickback' => '対象請求が紐づいていないため再生成できません。']);
        }

        try {
            $kickbacks->syncForSourceInvoice(
                $kickback->sourceInvoice,
                $request->user('admin'),
                force: true,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['kickback' => $exception->getMessage()]);
        }

        return back()->with('status', 'キックバックを再計算しました。');
    }
}
