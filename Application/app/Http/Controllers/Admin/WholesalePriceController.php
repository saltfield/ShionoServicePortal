<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class WholesalePriceController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'price.wholesale.edit');

        $sellerId = $request->filled('seller_bp_id') ? (int) $request->input('seller_bp_id') : null;
        $seller = $sellerId ? BusinessPartner::query()->find($sellerId) : null;
        $buyers = $seller
            ? BusinessPartner::query()->where('parent_id', $seller->id)->orderBy('code')->get()
            : collect();

        return view('admin.prices.wholesale', [
            'sellers' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(),
            'seller' => $seller,
            'buyers' => $buyers,
            'items' => Item::query()->where('is_active', true)->orderBy('code')->get(),
            'service' => app(CatalogPricingService::class),
            'routePrefix' => 'admin',
        ]);
    }

    public function store(Request $request, CatalogPricingService $service): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'seller_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'buyer_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        $item = Item::query()->findOrFail($validated['item_id']);
        $seller = BusinessPartner::query()->findOrFail($validated['seller_bp_id']);
        $buyer = BusinessPartner::query()->findOrFail($validated['buyer_bp_id']);

        try {
            $service->upsertWholesalePrice(
                $request->user('admin'),
                $item,
                $seller,
                $buyer,
                $validated['amount'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['buyer_bp_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.prices.wholesale.index', ['seller_bp_id' => $seller->id])
            ->with('status', '卸価格を保存しました。');
    }
}
