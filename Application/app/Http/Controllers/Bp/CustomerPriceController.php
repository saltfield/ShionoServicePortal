<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Bp\Services\OrganizationMasterService;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerPriceController extends Controller
{
    public function edit(
        Request $request,
        Customer $customer,
        RbacService $rbac,
        OrganizationMasterService $org,
        CatalogPricingService $service,
    ): View {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'price.customer.edit'), 403);
        $org->ensureCustomerInScope($actor, $customer);
        $customer->load('managingBp');

        $items = Item::query()->where('is_active', true)->with('requiredItem')->orderBy('code')->get();
        $amounts = [];
        foreach ($items as $item) {
            $amounts[$item->id] = $service->resolveCustomerAmount($item, $customer, $actor->businessPartner);
        }

        return view('admin.prices.customer', [
            'customer' => $customer,
            'items' => $items,
            'amounts' => $amounts,
            'routePrefix' => 'bp',
        ]);
    }

    public function update(
        Request $request,
        Customer $customer,
        OrganizationMasterService $org,
        CatalogPricingService $service,
    ): RedirectResponse {
        $actor = $request->user('bp');
        $org->ensureCustomerInScope($actor, $customer);

        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach ($validated['prices'] as $itemId => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }
            $item = Item::query()->findOrFail((int) $itemId);
            $service->upsertCustomerPrice($actor, $item, $customer, $amount);
        }

        return redirect()
            ->route('bp.customers.prices.edit', $customer)
            ->with('status', 'カスタマー価格を保存しました。');
    }
}
