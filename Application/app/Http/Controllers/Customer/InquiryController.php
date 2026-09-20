<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class InquiryController extends Controller
{
    public function index(Request $request, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);

        return view('admin.inquiries.index', [
            'inquiries' => $service->visibleQuery($actor)->latest('updated_at')->paginate(20),
            'routePrefix' => 'customer',
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        abort_unless($rbac->hasPermission($request->user('customer'), 'inquiry.reply'), 403);

        return view('admin.inquiries.create', [
            'routePrefix' => 'customer',
            'customers' => collect(),
            'businessPartners' => collect(),
        ]);
    }

    public function store(Request $request, InquiryService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $inquiry = $service->open(
                $request->user('customer'),
                $validated['subject'],
                $validated['body'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subject' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.inquiries.show', $inquiry)
            ->with('status', '問い合わせを作成しました。');
    }

    public function show(Request $request, Inquiry $inquiry, InquiryService $service, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'inquiry.view'), 403);
        $service->assertVisible($actor, $inquiry);

        return view('admin.inquiries.show', [
            'inquiry' => $inquiry->load(['customer', 'owningBp', 'openedBy']),
            'routePrefix' => 'customer',
            'deleteConfirmationCode' => null,
            'canClose' => false,
            'canReopen' => false,
        ]);
    }
}
