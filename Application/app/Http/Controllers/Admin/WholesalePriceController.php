<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Models\BpWholesalePrice;
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

        $buyerId = $request->filled('buyer_bp_id') ? (int) $request->input('buyer_bp_id') : null;
        $selectedBuyer = $buyerId
            ? $buyers->firstWhere('id', $buyerId)
            : null;

        $items = collect();
        $wholesaleByItem = collect();
        if ($seller && $selectedBuyer) {
            $items = Item::query()
                ->where('is_active', true)
                ->where(function ($query) use ($seller) {
                    $query->whereNull('owning_bp_id')
                        ->orWhere('owning_bp_id', $seller->id);
                })
                ->orderBy('code')
                ->get();
            $wholesaleByItem = BpWholesalePrice::query()
                ->where('seller_bp_id', $seller->id)
                ->where('buyer_bp_id', $selectedBuyer->id)
                ->whereIn('item_id', $items->pluck('id'))
                ->get()
                ->keyBy('item_id');
        }

        return view('admin.prices.wholesale', [
            'sellers' => BusinessPartner::query()->orderBy('depth')->orderBy('code')->get(),
            'seller' => $seller,
            'buyers' => $buyers,
            'selectedBuyer' => $selectedBuyer,
            'items' => $items,
            'wholesaleByItem' => $wholesaleByItem,
            'service' => app(CatalogPricingService::class),
            'routePrefix' => 'admin',
        ]);
    }

    public function store(Request $request, CatalogPricingService $service): RedirectResponse
    {
        $validated = $request->validate([
            'seller_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'buyer_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['required', 'integer', 'min:0'],
        ]);

        $seller = BusinessPartner::query()->findOrFail($validated['seller_bp_id']);
        $buyer = BusinessPartner::query()->findOrFail($validated['buyer_bp_id']);
        $actor = $request->user('admin');

        try {
            foreach ($validated['amounts'] as $itemId => $amount) {
                $item = Item::query()->findOrFail((int) $itemId);
                $service->upsertWholesalePrice($actor, $item, $seller, $buyer, $amount);
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amounts' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.prices.wholesale.index', [
                'seller_bp_id' => $seller->id,
                'buyer_bp_id' => $buyer->id,
            ])
            ->with('status', '卸価格を保存しました。');
    }
}
