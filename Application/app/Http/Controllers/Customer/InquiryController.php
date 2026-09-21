<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Enums\InquiryVisibility;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Concerns\DownloadsInquiryAttachment;
use App\Http\Controllers\Concerns\ListsTickets;
use App\Http\Controllers\Controller;
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
    use DownloadsInquiryAttachment;
    use ListsTickets;

    public function received(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $filters = $this->ticketListFilters($request);

        return view('admin.tickets.index', [
            'inquiries' => $this->paginateTicketList($request, $service, $actor, 'received'),
            'routePrefix' => 'customer',
            'listMode' => 'received',
            'pageTitle' => '受領チケット',
            'filters' => $filters,
        ]);
    }

    public function issued(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $filters = $this->ticketListFilters($request);

        return view('admin.tickets.index', [
            'inquiries' => $this->paginateTicketList($request, $service, $actor, 'issued'),
            'routePrefix' => 'customer',
            'listMode' => 'issued',
            'pageTitle' => '発行チケット',
            'canCreate' => $rbac->hasPermission($actor, 'inquiry.reply'),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        abort_unless($rbac->hasPermission($request->user('customer'), 'inquiry.reply'), 403);

        return view('admin.tickets.create', [
            'routePrefix' => 'customer',
            'customers' => collect(),
        ]);
    }

    public function store(Request $request, InquiryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::enum(InquiryVisibility::class)],
            'attachments' => ['nullable', 'array', 'max:'.InquiryService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'max:10240',
                'mimes:png,jpg,jpeg,gif,heic,heif,pdf',
            ],
        ]);

        try {
            $inquiry = $service->open(
                $request->user('customer'),
                $validated['subject'],
                $validated['body'],
                InquiryVisibility::from($validated['visibility']),
                null,
                false,
                $request->file('attachments', []) ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subject' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.tickets.show', $inquiry)
            ->with('status', 'チケットを発行しました。');
    }

    public function show(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertVisible($actor, $inquiry);
        $service->markRead($actor, $inquiry);

        return view('admin.tickets.show', [
            'inquiry' => $inquiry->load(['customer', 'assigneeBp', 'issuerBp', 'openedBy']),
            'routePrefix' => 'customer',
            'listMode' => 'issued',
            'deleteConfirmationCode' => null,
            'canStartProgress' => false,
            'canClose' => false,
            'canReopen' => false,
            'canWithdraw' => (int) $inquiry->opened_by_user_id === (int) $actor->id
                && $inquiry->status === InquiryStatus::Submitted,
        ]);
    }

    public function withdraw(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->withdraw($request->user('customer'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', 'チケットを取下げました。');
    }

    public function downloadAttachment(
        Request $request,
        Inquiry $inquiry,
        InquiryMessageAttachment $attachment,
        InquiryService $service,
    ): StreamedResponse {
        return $this->streamInquiryAttachment($request->user('customer'), $inquiry, $attachment, $service);
    }
}
