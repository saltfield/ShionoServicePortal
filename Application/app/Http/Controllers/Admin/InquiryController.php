<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Concerns\ConfirmsInquiryDeletion;
use App\Http\Controllers\Concerns\DownloadsInquiryAttachment;
use App\Http\Controllers\Concerns\ListsTickets;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryMessageAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InquiryController extends Controller
{
    use ConfirmsInquiryDeletion;
    use DownloadsInquiryAttachment;
    use ListsTickets;

    public function received(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $filters = $this->ticketListFilters($request);

        return view('admin.tickets.index', [
            'inquiries' => $this->paginateTicketList($request, $service, $actor, 'received'),
            'routePrefix' => 'admin',
            'listMode' => 'received',
            'pageTitle' => '受領チケット',
            'filters' => $filters,
        ]);
    }

    public function inspect(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertAdminInspectable($actor, $inquiry);

        $backUrl = null;
        $backLabel = null;
        if ($request->filled('return_bp_id')) {
            $backUrl = route('admin.business-partners.show', [
                'businessPartner' => (int) $request->input('return_bp_id'),
                'tab' => 'tickets',
            ]);
            $backLabel = 'BP詳細（チケット）';
        } elseif ($request->filled('return_customer_id')) {
            $backUrl = route('admin.customers.show', [
                'customer' => (int) $request->input('return_customer_id'),
                'tab' => 'tickets',
            ]);
            $backLabel = 'カスタマー詳細（チケット）';
        }

        return view('admin.tickets.inspect', [
            'inquiry' => $inquiry->load(['customer', 'assigneeBp', 'issuerBp', 'openedBy']),
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    public function show(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('admin');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertVisible($actor, $inquiry);
        $service->markRead($actor, $inquiry);

        $isAssignee = $service->isAssigneeSide($actor, $inquiry);

        return view('admin.tickets.show', [
            'inquiry' => $inquiry->load(['customer', 'assigneeBp', 'issuerBp', 'openedBy']),
            'routePrefix' => 'admin',
            'listMode' => 'received',
            'deleteConfirmationCode' => $this->issueInquiryDeleteConfirmationCode($inquiry),
            'canStartProgress' => $isAssignee
                && $rbac->hasPermission($actor, 'inquiry.reply')
                && $inquiry->status === InquiryStatus::Submitted,
            'canClose' => $isAssignee
                && $rbac->hasPermission($actor, 'inquiry.close')
                && $inquiry->status === InquiryStatus::InProgress,
            'canReopen' => $isAssignee
                && $rbac->hasPermission($actor, 'inquiry.reopen')
                && $inquiry->status === InquiryStatus::Closed,
            'canWithdraw' => false,
        ]);
    }

    public function startProgress(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->startProgress($request->user('admin'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', '受領対応を開始しました。');
    }

    public function close(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->close($request->user('admin'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットをクローズしました。');
    }

    public function reopen(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->reopen($request->user('admin'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットを再オープンしました。');
    }

    public function destroy(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        $this->assertInquiryDeleteConfirmation($request, $inquiry);
        $service->delete($request->user('admin'), $inquiry);

        return redirect()
            ->route('admin.tickets.received')
            ->with('status', 'チケットを削除しました。');
    }

    public function downloadAttachment(
        Request $request,
        Inquiry $inquiry,
        InquiryMessageAttachment $attachment,
        InquiryService $service,
    ): StreamedResponse {
        return $this->streamInquiryAttachment($request->user('admin'), $inquiry, $attachment, $service);
    }
}
