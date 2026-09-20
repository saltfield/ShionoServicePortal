<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\RbacService;
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

        return view('admin.prices.wholesale', [
            'sellers' => collect([$seller]),
            'seller' => $seller,
            'buyers' => $buyers,
            'items' => Item::query()->where('is_active', true)->orderBy('code')->get(),
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
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'buyer_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        $item = Item::query()->findOrFail($validated['item_id']);
        $buyer = BusinessPartner::query()->findOrFail($validated['buyer_bp_id']);

        try {
            $service->upsertWholesalePrice($actor, $item, $seller, $buyer, $validated['amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['buyer_bp_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.prices.wholesale.index')
            ->with('status', '卸価格を保存しました。');
    }
}
