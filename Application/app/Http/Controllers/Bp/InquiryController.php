<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Concerns\ConfirmsInquiryDeletion;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class InquiryController extends Controller
{
    use ConfirmsInquiryDeletion;

    public function index(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);

        return view('admin.inquiries.index', [
            'inquiries' => $service->visibleQuery($actor)->latest('updated_at')->paginate(20),
            'routePrefix' => 'bp',
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.reply'), 403);

        $scopeIds = app(\App\Domains\Auth\Services\BpHierarchyService::class)
            ->descendantIdsIncludingSelf($actor->businessPartner);

        return view('admin.inquiries.create', [
            'routePrefix' => 'bp',
            'customers' => Customer::query()->whereIn('managing_bp_id', $scopeIds)->orderBy('code')->get(),
            'businessPartners' => collect(),
        ]);
    }

    public function store(Request $request, InquiryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        try {
            $inquiry = $service->open(
                $request->user('bp'),
                $validated['subject'],
                $validated['body'],
                isset($validated['customer_id']) ? Customer::query()->find($validated['customer_id']) : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subject' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.inquiries.show', $inquiry)
            ->with('status', '問い合わせを作成しました。');
    }

    public function show(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertVisible($actor, $inquiry);

        return view('admin.inquiries.show', [
            'inquiry' => $inquiry->load(['customer', 'owningBp', 'openedBy']),
            'routePrefix' => 'bp',
            'deleteConfirmationCode' => $this->issueInquiryDeleteConfirmationCode($inquiry),
            'canClose' => $rbac->hasPermission($actor, 'inquiry.close'),
            'canReopen' => $rbac->hasPermission($actor, 'inquiry.reopen'),
        ]);
    }

    public function close(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        $service->close($request->user('bp'), $inquiry);

        return back()->with('status', '問い合わせをクローズしました。');
    }

    public function reopen(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        try {
            $service->reopen($request->user('bp'), $inquiry);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['inquiry' => $exception->getMessage()]);
        }

        return back()->with('status', '問い合わせを再オープンしました。');
    }

    public function destroy(Request $request, Inquiry $inquiry, InquiryService $service): RedirectResponse
    {
        $this->assertInquiryDeleteConfirmation($request, $inquiry);
        $service->delete($request->user('bp'), $inquiry);

        return redirect()
            ->route('bp.inquiries.index')
            ->with('status', '問い合わせを削除しました。');
    }
}
