<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Billing\Services\BpCustomerBillingOverviewService;
use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\InquiryService;
use App\Http\Controllers\Concerns\ConfirmsBusinessPartnerDeletion;
use App\Http\Controllers\Concerns\ConfirmsBusinessPartnerMove;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Support\ContactFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class BusinessPartnerController extends Controller
{
    use ConfirmsBusinessPartnerDeletion;
    use ConfirmsBusinessPartnerMove;

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'bp.view');

        $bpn = trim((string) $request->input('bpn', ''));
        $bpName = trim((string) $request->input('bp_name', ''));

        $partners = BusinessPartner::query()
            ->with('parent')
            ->when($bpn !== '', function ($query) use ($bpn) {
                $normalized = IdentifierNormalizer::normalize($bpn);
                $query->where('code', 'like', "%{$normalized}%");
            })
            ->when($bpName !== '', fn ($query) => $query->where('name', 'like', "%{$bpName}%"))
            ->orderBy('depth')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('admin.business-partners.index', [
            'partners' => $partners,
            'filters' => ['bpn' => $bpn, 'bp_name' => $bpName],
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'bp.manage');

        return view('admin.business-partners.create', [
            'parents' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(),
            'modes' => TwoFactorMode::cases(),
            'selectedParentId' => $request->integer('parent_id') ?: null,
        ]);
    }

    public function store(Request $request, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedPartner($request);

        $parent = isset($validated['parent_id'])
            ? BusinessPartner::query()->findOrFail($validated['parent_id'])
            : null;

        try {
            $partner = $service->createBp($request->user('admin'), [
                'name' => $validated['name'],
                'two_factor_mode' => $validated['two_factor_mode'],
                'is_active' => $request->boolean('is_active', true),
                'postal_code' => $validated['postal_code'] ?? null,
                'address' => $validated['address'] ?? null,
                'building_name' => $validated['building_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
            ], $parent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.business-partners.show', $partner)
            ->with('status', "{$partner->code} を作成しました。");
    }

    public function show(Request $request, BusinessPartner $businessPartner, AuthorizationService $authorization, RbacService $rbac, InquiryService $inquiries, BpCustomerBillingOverviewService $billingOverview): View
    {
        $authorization->authorize($request->user('admin'), 'bp.view');
        $businessPartner->load(['parent', 'children']);
        $actor = $request->user('admin');
        $canManageUsers = $rbac->hasPermission($actor, 'iam.user.manage');
        $canViewCustomers = $rbac->hasPermission($actor, 'customer.view');
        $canManageCustomers = $rbac->hasPermission($actor, 'customer.manage');
        $canViewTickets = $rbac->hasPermission($actor, 'inquiry.view');
        $canViewBilling = $rbac->hasPermission($actor, 'invoice.view');
        $canViewContracts = $rbac->hasPermission($actor, 'contract.view');
        $activeTab = match ($request->input('tab')) {
            'users' => $canManageUsers ? 'users' : 'overview',
            'customers' => $canViewCustomers ? 'customers' : 'overview',
            'contracts' => $canViewContracts ? 'contracts' : 'overview',
            'tickets' => $canViewTickets ? 'tickets' : 'overview',
            'billing' => $canViewBilling ? 'billing' : 'overview',
            default => 'overview',
        };

        $bpTickets = collect();
        if ($activeTab === 'tickets' && $canViewTickets) {
            $bpTickets = $inquiries->adminBpReceivedQuery($businessPartner)
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString();
        }

        $billing = null;
        if ($activeTab === 'billing' && $canViewBilling) {
            $billing = $billingOverview->forPartner($businessPartner);
        }

        $bpContracts = $canViewContracts
            ? Contract::query()
                ->with(['customer', 'site'])
                ->where('owning_bp_id', $businessPartner->id)
                ->latest('id')
                ->get()
            : collect();

        return view('admin.business-partners.show', [
            'partner' => $businessPartner,
            'moveCandidates' => BusinessPartner::query()
                ->where('id', '!=', $businessPartner->id)
                ->orderBy('depth')
                ->orderBy('code')
                ->get(),
            'modes' => TwoFactorMode::cases(),
            'routePrefix' => 'admin',
            'deleteConfirmationCode' => $this->issueBusinessPartnerDeleteConfirmationCode($businessPartner),
            'moveConfirmationCode' => $this->issueBusinessPartnerMoveConfirmationCode($businessPartner),
            'canManageUsers' => $canManageUsers,
            'canViewCustomers' => $canViewCustomers,
            'canManageCustomers' => $canManageCustomers,
            'canViewTickets' => $canViewTickets,
            'canViewBilling' => $canViewBilling,
            'canViewContracts' => $canViewContracts,
            'bpUsers' => $canManageUsers
                ? User::query()->with('roles')->where('bp_id', $businessPartner->id)->orderBy('login_id')->get()
                : collect(),
            'bpCustomers' => $canViewCustomers
                ? Customer::query()->where('managing_bp_id', $businessPartner->id)->orderBy('code')->get()
                : collect(),
            'bpContracts' => $bpContracts,
            'bpTickets' => $bpTickets,
            'billing' => $billing,
            'activeTab' => $activeTab,
        ]);
    }

    public function edit(Request $request, BusinessPartner $businessPartner, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'bp.manage');

        return view('admin.business-partners.edit', [
            'partner' => $businessPartner,
            'modes' => TwoFactorMode::cases(),
        ]);
    }

    public function update(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedPartner($request, requireParent: false);

        $service->updateBp($request->user('admin'), $businessPartner, [
            'name' => $validated['name'],
            'two_factor_mode' => $validated['two_factor_mode'],
            'is_active' => $request->boolean('is_active'),
            'postal_code' => $validated['postal_code'] ?? null,
            'address' => $validated['address'] ?? null,
            'building_name' => $validated['building_name'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        return redirect()
            ->route('admin.business-partners.show', $businessPartner)
            ->with('status', 'BP情報を更新しました。');
    }

    public function move(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertBusinessPartnerMoveConfirmation($request, $businessPartner);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:business_partners,id'],
        ]);

        $newParent = isset($validated['parent_id'])
            ? BusinessPartner::query()->findOrFail($validated['parent_id'])
            : null;

        try {
            $service->moveBp($request->user('admin'), $businessPartner, $newParent);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['parent_id' => $exception->getMessage()]);
        }

        return back()->with('status', '階層を移動しました。');
    }

    public function destroy(Request $request, BusinessPartner $businessPartner, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertBusinessPartnerDeleteConfirmation($request, $businessPartner);

        try {
            $service->deleteBp($request->user('admin'), $businessPartner);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['partner' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.business-partners.index')
            ->with('status', 'BPを削除しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPartner(Request $request, bool $requireParent = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
            'is_active' => ['nullable', 'boolean'],
            'postal_code' => ContactFieldRules::postalCode(),
            'address' => ['nullable', 'string', 'max:255'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'phone' => ContactFieldRules::phone(),
            'email' => ['nullable', 'email', 'max:255'],
        ];

        if ($requireParent) {
            $rules['parent_id'] = ['nullable', 'integer', 'exists:business_partners,id'];
        }

        $validated = $request->validate($rules, [
            'postal_code.regex' => '郵便番号は半角数字（例: 100-0001）で入力してください。',
            'phone.regex' => '電話番号は半角数字とハイフンのみで入力してください。',
        ]);

        $validated['postal_code'] = ContactFieldRules::normalizePostalCode($validated['postal_code'] ?? null);
        $validated['phone'] = ContactFieldRules::normalizePhone($validated['phone'] ?? null);

        return $validated;
    }
}
