<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsCustomerDeletion;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Support\ContactFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CustomerController extends Controller
{
    use ConfirmsCustomerDeletion;

    public function index(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'customer.view');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $scopeIds = $hierarchy->descendantIdsIncludingSelf($actorBp);
        $managingBpId = $request->filled('managing_bp_id') ? (int) $request->input('managing_bp_id') : null;
        if ($managingBpId !== null) {
            abort_unless(in_array($managingBpId, $scopeIds, true), 403);
        }

        $cn = trim((string) $request->input('cn', ''));
        $cnName = trim((string) $request->input('cn_name', ''));

        $customers = Customer::query()
            ->with('managingBp')
            ->whereIn('managing_bp_id', $scopeIds)
            ->when($managingBpId !== null, fn ($query) => $query->where('managing_bp_id', $managingBpId))
            ->when($cn !== '', function ($query) use ($cn) {
                $normalized = IdentifierNormalizer::normalize($cn);
                $query->where('code', 'like', "%{$normalized}%");
            })
            ->when($cnName !== '', fn ($q) => $q->where('name', 'like', "%{$cnName}%"))
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'managingPartners' => BusinessPartner::query()->whereIn('id', $scopeIds)->orderBy('depth')->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'managing_bp_id' => $managingBpId,
                'cn' => $cn,
                'cn_name' => $cnName,
            ],
            'routePrefix' => 'bp',
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'customer.manage');
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);
        $scopeIds = $hierarchy->descendantIdsIncludingSelf($actorBp);

        return view('admin.customers.create', [
            'managingPartners' => BusinessPartner::query()->whereIn('id', $scopeIds)->orderBy('depth')->orderBy('code')->get(),
            'modes' => TwoFactorMode::cases(),
            'entityTypes' => EntityType::cases(),
            'selectedManagingBpId' => $request->integer('managing_bp_id') ?: $actorBp->id,
            'routePrefix' => 'bp',
        ]);
    }

    public function store(Request $request, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedCustomer($request, requireManagingBp: true);
        $managingBp = BusinessPartner::query()->findOrFail($validated['managing_bp_id']);

        $customer = $service->createCustomer($request->user('bp'), $managingBp, $validated);

        return redirect()
            ->route('bp.customers.show', ['customer' => $customer, 'tab' => 'overview'])
            ->with('status', "{$customer->code} を作成しました。");
    }

    public function show(Request $request, Customer $customer, OrganizationMasterService $service, AuthorizationService $authorization, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'customer.view');
        $service->ensureCustomerInScope($actor, $customer);
        $customer->load(['managingBp', 'sites']);

        $canManageUsers = $rbac->hasPermission($actor, 'iam.user.manage');
        $canViewContracts = $rbac->hasPermission($actor, 'contract.view');
        $canCreateContracts = $rbac->hasPermission($actor, 'contract.create');
        $canViewInvoices = $rbac->hasPermission($actor, 'invoice.view');
        $customerUsers = $canManageUsers
            ? $customer->users()->with('roles')->orderBy('login_id')->get()
            : collect();
        $customerContracts = $canViewContracts
            ? $customer->contracts()->with(['site', 'owningBp'])->latest()->get()
            : collect();
        $customerInvoices = $canViewInvoices
            ? $customer->invoices()->with(['owningBp', 'issuerBp', 'contract'])->latest('id')->paginate(20)->withQueryString()
            : null;

        return view('admin.customers.show', [
            'customer' => $customer,
            'routePrefix' => 'bp',
            'deleteConfirmationCode' => $this->issueCustomerDeleteConfirmationCode($customer),
            'canManageUsers' => $canManageUsers,
            'canViewContracts' => $canViewContracts,
            'canCreateContracts' => $canCreateContracts,
            'canViewInvoices' => $canViewInvoices,
            'customerUsers' => $customerUsers,
            'customerContracts' => $customerContracts,
            'customerInvoices' => $customerInvoices,
            'activeTab' => $this->resolveCustomerShowTab($request, $canManageUsers, $canViewContracts, $canViewInvoices),
        ]);
    }

    public function edit(Request $request, Customer $customer, OrganizationMasterService $service, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'customer.manage');
        $service->ensureCustomerInScope($actor, $customer);

        return view('admin.customers.edit', [
            'customer' => $customer,
            'modes' => TwoFactorMode::cases(),
            'entityTypes' => EntityType::cases(),
            'routePrefix' => 'bp',
        ]);
    }

    public function update(Request $request, Customer $customer, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedCustomer($request, requireManagingBp: false);
        $service->updateCustomer($request->user('bp'), $customer, $validated);

        return redirect()
            ->route('bp.customers.show', ['customer' => $customer, 'tab' => 'overview'])
            ->with('status', 'カスタマー情報を更新しました。');
    }

    public function destroy(Request $request, Customer $customer, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertCustomerDeleteConfirmation($request, $customer);

        try {
            $service->deleteCustomer($request->user('bp'), $customer);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['customer' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.customers.index', ['managing_bp_id' => $customer->managing_bp_id])
            ->with('status', 'カスタマーを削除しました。');
    }

    private function resolveCustomerShowTab(
        Request $request,
        bool $canManageUsers,
        bool $canViewContracts,
        bool $canViewInvoices = false,
    ): string {
        $tab = (string) $request->input('tab', 'overview');
        $allowed = ['overview', 'sites', 'prices'];
        if ($canManageUsers) {
            $allowed[] = 'users';
        }
        if ($canViewContracts) {
            $allowed[] = 'contracts';
        }
        if ($canViewInvoices) {
            $allowed[] = 'invoices';
        }

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private function validatedCustomer(Request $request, bool $requireManagingBp = true): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'name_kana' => ['nullable', 'string', 'max:255'],
            'postal_code' => ContactFieldRules::postalCode(),
            'address' => ['nullable', 'string', 'max:500'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'phone' => ContactFieldRules::phone(),
            'email' => ['nullable', 'email', 'max:255'],
            'entity_type' => ['required', Rule::enum(EntityType::class)],
            'two_factor_mode' => ['required', Rule::enum(TwoFactorMode::class)],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($requireManagingBp) {
            $rules['managing_bp_id'] = ['required', 'integer', 'exists:business_partners,id'];
        }

        $validated = $request->validate($rules, [
            'postal_code.regex' => '郵便番号は半角数字（例: 100-0001）で入力してください。',
            'phone.regex' => '電話番号は半角数字とハイフンのみで入力してください。',
        ]);
        $validated['is_active'] = $request->boolean('is_active', $requireManagingBp);
        $validated['postal_code'] = ContactFieldRules::normalizePostalCode($validated['postal_code'] ?? null);
        $validated['phone'] = ContactFieldRules::normalizePhone($validated['phone'] ?? null);

        return $validated;
    }
}
