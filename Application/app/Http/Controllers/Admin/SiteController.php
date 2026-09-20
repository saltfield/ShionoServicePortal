<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Concerns\ConfirmsSiteDeletion;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Site;
use App\Support\ContactFieldRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    use ConfirmsSiteDeletion;
    public function create(Request $request, Customer $customer, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'site.manage');

        return view('admin.sites.create', compact('customer'));
    }

    public function store(Request $request, Customer $customer, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validated($request);
        $site = $service->createSite($request->user('admin'), $customer, $validated);

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('status', "拠点「{$site->name}」を作成しました。");
    }

    public function edit(Request $request, Site $site, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'site.manage');
        $site->load('customer');

        return view('admin.sites.edit', [
            'site' => $site,
            'deleteConfirmationCode' => $this->issueSiteDeleteConfirmationCode($site),
        ]);
    }

    public function update(Request $request, Site $site, OrganizationMasterService $service): RedirectResponse
    {
        $validated = $this->validated($request);
        $service->updateSite($request->user('admin'), $site, $validated);

        return redirect()
            ->route('admin.customers.show', $site->customer_id)
            ->with('status', '拠点情報を更新しました。');
    }

    public function destroy(Request $request, Site $site, OrganizationMasterService $service): RedirectResponse
    {
        $this->assertSiteDeleteConfirmation($request, $site);

        $customerId = $site->customer_id;
        $service->deleteSite($request->user('admin'), $site);

        return redirect()
            ->route('admin.customers.show', $customerId)
            ->with('status', '拠点を削除しました。');
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'postal_code' => ContactFieldRules::postalCode(),
            'address' => ['nullable', 'string', 'max:500'],
            'building_name' => ['nullable', 'string', 'max:255'],
            'phone' => ContactFieldRules::phone(),
            'billing_name' => ['required', 'string', 'max:255'],
            'billing_department' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ContactFieldRules::postalCode(),
            'billing_address' => ['nullable', 'string', 'max:500'],
            'billing_building_name' => ['nullable', 'string', 'max:255'],
            'billing_phone' => ContactFieldRules::phone(),
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'postal_code.regex' => '郵便番号は半角数字（例: 100-0001）で入力してください。',
            'billing_postal_code.regex' => '請求書送付先の郵便番号は半角数字（例: 100-0001）で入力してください。',
            'phone.regex' => '電話番号は半角数字とハイフンのみで入力してください。',
            'billing_phone.regex' => '請求書送付先の電話番号は半角数字とハイフンのみで入力してください。',
        ]);

        $validated['is_primary'] = $request->boolean('is_primary');
        $validated['is_active'] = $request->boolean('is_active');
        $validated['postal_code'] = ContactFieldRules::normalizePostalCode($validated['postal_code'] ?? null);
        $validated['phone'] = ContactFieldRules::normalizePhone($validated['phone'] ?? null);
        $validated['billing_postal_code'] = ContactFieldRules::normalizePostalCode($validated['billing_postal_code'] ?? null);
        $validated['billing_phone'] = ContactFieldRules::normalizePhone($validated['billing_phone'] ?? null);

        return $validated;
    }
}
