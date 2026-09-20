<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerPriceController extends Controller
{
    public function edit(Request $request, Customer $customer, AuthorizationService $authorization, CatalogPricingService $service): View
    {
        $authorization->authorize($request->user('admin'), 'price.customer.edit');
        $customer->load('managingBp');

        $items = Item::query()->where('is_active', true)->with('requiredItem')->orderBy('code')->get();
        $amounts = [];
        foreach ($items as $item) {
            $amounts[$item->id] = $service->resolveCustomerAmount($item, $customer, $customer->managingBp);
        }

        return view('admin.prices.customer', [
            'customer' => $customer,
            'items' => $items,
            'amounts' => $amounts,
            'routePrefix' => 'admin',
        ]);
    }

    public function update(Request $request, Customer $customer, CatalogPricingService $service): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach ($validated['prices'] as $itemId => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }
            $item = Item::query()->findOrFail((int) $itemId);
            $service->upsertCustomerPrice($request->user('admin'), $item, $customer, $amount);
        }

        return redirect()
            ->route('admin.customers.prices.edit', $customer)
            ->with('status', 'カスタマー価格を保存しました。');
    }
}
