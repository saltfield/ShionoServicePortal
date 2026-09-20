<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Iam\Services\AuthorizationService;
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

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'customer.view');

        $managingBpId = $request->filled('managing_bp_id') ? (int) $request->input('managing_bp_id') : null;
        $cn = trim((string) $request->input('cn', ''));
        $cnName = trim((string) $request->input('cn_name', ''));

        $customers = Customer::query()
            ->with('managingBp')
            ->when($managingBpId === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($managingBpId !== null, function ($query) use ($managingBpId, $cn, $cnName) {
                $query->where('managing_bp_id', $managingBpId);
                if ($cn !== '') {
                    $normalized = IdentifierNormalizer::normalize($cn);
                    $query->where('code', 'like', "%{$normalized}%");
                }
                if ($cnName !== '') {
                    $query->where('name', 'like', "%{$cnName}%");
                }
            })
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('admin.customers.index', [
            'customers' => $customers,
            'managingPartners' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'managing_bp_id' => $managingBpId,
                'cn' => $cn,
                'cn_name' => $cnName,
            ],
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'customer.manage');

        return view('admin.customers.create', [
            'managingPartners' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(),
            'modes' => TwoFactorMode::cases(),
            'entityTypes' => EntityType::cases(),
            'selectedManagingBpId' => $request->integer('managing_bp_id') ?: null,
        ]);
    }

    public function store(Request $request, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedCustomer($request);
        $managingBp = BusinessPartner::query()->findOrFail($validated['managing_bp_id']);

        $customer = $service->createCustomer($request->user('admin'), $managingBp, $validated);

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('status', "{$customer->code} を作成しました。");
    }

    public function show(Request $request, Customer $customer, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'customer.view');
        $customer->load(['managingBp', 'sites']);

        return view('admin.customers.show', [
            'customer' => $customer,
            'deleteConfirmationCode' => $this->issueCustomerDeleteConfirmationCode($customer),
        ]);
    }

    public function edit(Request $request, Customer $customer, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'customer.manage');

        return view('admin.customers.edit', [
            'customer' => $customer,
            'modes' => TwoFactorMode::cases(),
            'entityTypes' => EntityType::cases(),
        ]);
    }

    public function update(Request $request, Customer $customer, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validatedCustomer($request, requireManagingBp: false);
        $service->updateCustomer($request->user('admin'), $customer, $validated);

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('status', 'カスタマー情報を更新しました。');
    }

    public function destroy(Request $request, Customer $customer, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertCustomerDeleteConfirmation($request, $customer);

        try {
            $service->deleteCustomer($request->user('admin'), $customer);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['customer' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.customers.index', ['managing_bp_id' => $customer->managing_bp_id])
            ->with('status', 'カスタマーを削除しました。');
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
