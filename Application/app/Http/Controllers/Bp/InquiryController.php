<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Enums\InquiryVisibility;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Concerns\ConfirmsInquiryDeletion;
use App\Http\Controllers\Concerns\DownloadsInquiryAttachment;
use App\Http\Controllers\Concerns\ListsTickets;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryMessageAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $filters = $this->ticketListFilters($request);

        return view('admin.tickets.index', [
            'inquiries' => $this->paginateTicketList($request, $service, $actor, 'received'),
            'routePrefix' => 'bp',
            'listMode' => 'received',
            'pageTitle' => '受領チケット',
            'filters' => $filters,
        ]);
    }

    public function issued(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $filters = $this->ticketListFilters($request);

        return view('admin.tickets.index', [
            'inquiries' => $this->paginateTicketList($request, $service, $actor, 'issued'),
            'routePrefix' => 'bp',
            'listMode' => 'issued',
            'pageTitle' => '発行チケット',
            'canCreate' => $rbac->hasPermission($actor, 'inquiry.reply'),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.reply'), 403);

        $hierarchy = app(BpHierarchyService::class);
        $actorBp = $actor->businessPartner;
        $scopeIds = $actorBp ? $hierarchy->descendantIdsIncludingSelf($actorBp) : [];

        return view('admin.tickets.create', [
            'routePrefix' => 'bp',
            'customers' => Customer::query()->whereIn('managing_bp_id', $scopeIds)->orderBy('code')->get(),
            'childBusinessPartners' => $actorBp
                ? $hierarchy->descendants($actorBp, includeSelf: false)
                : collect(),
        ]);
    }

    public function store(Request $request, InquiryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::enum(InquiryVisibility::class)],
            'proxy_enabled' => ['sometimes', 'boolean'],
            'proxy_target' => [
                Rule::requiredIf(fn () => $request->boolean('proxy_enabled')),
                'nullable',
                'string',
                'regex:/^(customer|bp):\d+$/',
            ],
            'attachments' => ['nullable', 'array', 'max:'.InquiryService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimes:png,jpg,jpeg,gif,heic,heif,pdf',
            ],
        ]);

        $customer = null;
        $proxyForCustomer = false;
        $proxyBp = null;

        if ($request->boolean('proxy_enabled')) {
            [$kind, $id] = explode(':', (string) $validated['proxy_target'], 2);
            if ($kind === 'customer') {
                $customer = Customer::query()->findOrFail((int) $id);
                $proxyForCustomer = true;
            } else {
                $proxyBp = BusinessPartner::query()->findOrFail((int) $id);
            }
        }

        try {
            $inquiry = $service->open(
                $request->user('bp'),
                $validated['subject'],
                $validated['body'],
                InquiryVisibility::from($validated['visibility']),
                $customer,
                $proxyForCustomer,
                $request->file('attachments', []) ?? [],
                $proxyBp,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subject' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.tickets.show', $inquiry)
            ->with('status', 'チケットを発行しました。');
    }

    public function show(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertVisible($actor, $inquiry);
        $service->markRead($actor, $inquiry);

        $listMode = $service->isAssigneeSide($actor, $inquiry) ? 'received' : 'issued';
        $isAssignee = $service->isAssigneeSide($actor, $inquiry);

        return view('admin.tickets.show', [
            'inquiry' => $inquiry->load(['customer', 'assigneeBp', 'issuerBp', 'openedBy']),
            'routePrefix' => 'bp',
            'listMode' => $listMode,
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
            'canWithdraw' => (int) $inquiry->opened_by_user_id === (int) $actor->id
                && $inquiry->status === InquiryStatus::Submitted,
        ]);
    }

    public function withdraw(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->withdraw($request->user('bp'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットを取下げました。');
    }

    public function startProgress(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->startProgress($request->user('bp'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', '受領対応を開始しました。');
    }

    public function close(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->close($request->user('bp'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットをクローズしました。');
    }

    public function reopen(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->reopen($request->user('bp'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットを再オープンしました。');
    }

    public function destroy(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        $this->assertInquiryDeleteConfirmation($request, $inquiry);
        $service->delete($request->user('bp'), $inquiry);

        return redirect()
            ->route('bp.tickets.received')
            ->with('status', 'チケットを削除しました。');
    }

    public function downloadAttachment(
        Request $request,
        Inquiry $inquiry,
        InquiryMessageAttachment $attachment,
        InquiryService $service,
    ): StreamedResponse {
        return $this->streamInquiryAttachment($request->user('bp'), $inquiry, $attachment, $service);
    }
}
