<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\RbacService;
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
    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'price.wholesale.edit'), 403);
        $seller = $actor->businessPartner;
        abort_unless($seller, 403);

        $buyers = BusinessPartner::query()
            ->where('parent_id', $seller->id)
            ->orderBy('code')
            ->get();

        $buyerId = $request->filled('buyer_bp_id') ? (int) $request->input('buyer_bp_id') : null;
        $selectedBuyer = $buyerId
            ? $buyers->firstWhere('id', $buyerId)
            : null;

        $items = collect();
        $wholesaleByItem = collect();
        if ($selectedBuyer) {
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
            'sellers' => collect([$seller]),
            'seller' => $seller,
            'buyers' => $buyers,
            'selectedBuyer' => $selectedBuyer,
            'items' => $items,
            'wholesaleByItem' => $wholesaleByItem,
            'service' => app(CatalogPricingService::class),
            'routePrefix' => 'bp',
            'lockSeller' => true,
        ]);
    }

    public function store(Request $request, CatalogPricingService $service): RedirectResponse
    {
        $actor = $request->user('bp');
        $seller = $actor->businessPartner;
        abort_unless($seller, 403);

        $validated = $request->validate([
            'buyer_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'amounts' => ['required', 'array', 'min:1'],
            'amounts.*' => ['required', 'integer', 'min:0'],
        ]);

        $buyer = BusinessPartner::query()->findOrFail($validated['buyer_bp_id']);

        try {
            foreach ($validated['amounts'] as $itemId => $amount) {
                $item = Item::query()->findOrFail((int) $itemId);
                $service->upsertWholesalePrice($actor, $item, $seller, $buyer, $amount);
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amounts' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.prices.wholesale.index', ['buyer_bp_id' => $buyer->id])
            ->with('status', '卸価格を保存しました。');
    }
}
