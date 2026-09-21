<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsItemDeletion;
use App\Http\Controllers\Concerns\ConfirmsItemDocumentDeletion;
use App\Http\Controllers\Controller;
use App\Models\BusinessPartner;
use App\Models\BpWholesalePrice;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class ItemController extends Controller
{
    use ConfirmsItemDeletion;
    use ConfirmsItemDocumentDeletion;

    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        $this->assertCanBrowse($rbac, $actor);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        $items = Item::query()
            ->with('requiredItem')
            ->where(function ($query) use ($bp) {
                $query->whereNull('owning_bp_id')
                    ->orWhere('owning_bp_id', $bp->id);
            })
            ->orderByRaw('CASE WHEN owning_bp_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('code')
            ->paginate(20);

        return view('admin.items.index', [
            'items' => $items,
            'routePrefix' => 'bp',
            'readOnly' => false,
            'canCreate' => $rbac->hasPermission($actor, 'contract.create'),
            'showOwnership' => true,
            'actorBpId' => $bp->id,
        ]);
    }

    public function create(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        return view('admin.items.create', [
            'routePrefix' => 'bp',
            'billingTypes' => BillingType::cases(),
            'requiredCandidates' => $this->requiredCandidates($bp->id),
            'formMode' => 'bp_owned',
        ]);
    }

    public function store(Request $request, CatalogPricingService $service, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        $validated = $this->validatedItem($request);
        $validated['owning_bp_id'] = $bp->id;

        try {
            $item = $service->createItem($actor, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['required_item_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.items.show', $item)
            ->with('status', "{$item->code} を作成しました。");
    }

    public function show(Request $request, Item $item, RbacService $rbac, CatalogPricingService $pricing): View
    {
        $actor = $request->user('bp');
        $this->assertCanBrowse($rbac, $actor);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertVisible($item, $bp);

        $documents = $item->documents()
            ->where(function ($query) use ($bp) {
                $query->whereNull('owning_bp_id')
                    ->orWhere('owning_bp_id', $bp->id);
            })
            ->orderBy('sort_order')
            ->get();

        $item->setRelation('documents', $documents);
        $item->load('requiredItem');

        $canManageItem = $item->isBpOwned()
            && (int) $item->owning_bp_id === (int) $bp->id
            && $rbac->hasPermission($actor, 'contract.create');
        $canEditWholesale = $rbac->hasPermission($actor, 'price.wholesale.edit');
        $canManageDocuments = $rbac->hasPermission($actor, 'contract.create');

        $buyers = collect();
        $wholesaleByBuyer = collect();
        if ($canEditWholesale) {
            $buyers = BusinessPartner::query()
                ->where('parent_id', $bp->id)
                ->orderBy('code')
                ->get();
            $wholesaleByBuyer = BpWholesalePrice::query()
                ->where('item_id', $item->id)
                ->where('seller_bp_id', $bp->id)
                ->get()
                ->keyBy('buyer_bp_id');
        }

        $ownDocumentCount = $documents->where('owning_bp_id', $bp->id)->count();

        return view('admin.items.show', [
            'item' => $item,
            'routePrefix' => 'bp',
            'canManageItem' => $canManageItem,
            'canManageDocuments' => $canManageDocuments,
            'canEditWholesale' => $canEditWholesale,
            'actorBpId' => $bp->id,
            'buyers' => $buyers,
            'wholesaleByBuyer' => $wholesaleByBuyer,
            'pricing' => $pricing,
            'seller' => $bp,
            'ownDocumentCount' => $ownDocumentCount,
            'deleteConfirmationCode' => $canManageItem ? $this->issueItemDeleteConfirmationCode($item) : null,
            'documentDeleteCodes' => $documents
                ->filter(fn ($document) => (int) $document->owning_bp_id === (int) $bp->id)
                ->mapWithKeys(fn ($document) => [
                    $document->id => $this->issueItemDocumentDeleteConfirmationCode($document),
                ])
                ->all(),
        ]);
    }

    public function edit(Request $request, Item $item, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertOwnedBy($item, $bp);

        return view('admin.items.edit', [
            'item' => $item,
            'routePrefix' => 'bp',
            'billingTypes' => BillingType::cases(),
            'requiredCandidates' => $this->requiredCandidates($bp->id, $item->id),
            'formMode' => 'bp_owned',
        ]);
    }

    public function update(Request $request, Item $item, CatalogPricingService $service, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertOwnedBy($item, $bp);

        $validated = $this->validatedItem($request);

        try {
            $service->updateItem($actor, $item, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['required_item_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.items.show', $item)
            ->with('status', '品目を更新しました。');
    }

    public function destroy(Request $request, Item $item, CatalogPricingService $service, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertOwnedBy($item, $bp);
        $this->assertItemDeleteConfirmation($request, $item);

        try {
            $service->deleteItem($actor, $item);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.items.index')
            ->with('status', '品目を削除しました。');
    }

    public function storeWholesale(
        Request $request,
        Item $item,
        CatalogPricingService $service,
        RbacService $rbac,
    ): RedirectResponse {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'price.wholesale.edit'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertVisible($item, $bp);

        $validated = $request->validate([
            'buyer_bp_id' => ['required', 'integer', 'exists:business_partners,id'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        $buyer = BusinessPartner::query()->findOrFail($validated['buyer_bp_id']);

        try {
            $service->upsertWholesalePrice($actor, $item, $bp, $buyer, $validated['amount']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['buyer_bp_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.items.show', $item)
            ->with('status', '仕切り価格を保存しました。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedItem(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'required_item_id' => ['nullable', 'integer', 'exists:items,id'],
            'partition_price' => ['required', 'integer', 'min:0'],
            'recommended_price' => ['required', 'integer', 'min:0'],
            'user_price' => ['required', 'integer', 'min:0'],
            'tax_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'minimum_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['required_item_id'] = $validated['required_item_id'] ?? null;
        $validated['tax_rate'] = (int) $validated['tax_rate'];
        $validated['minimum_term_months'] = $validated['minimum_term_months'] ?? null;

        return $validated;
    }

    private function assertCanBrowse(RbacService $rbac, $actor): void
    {
        abort_unless(
            $rbac->hasPermission($actor, 'price.wholesale.edit')
            || $rbac->hasPermission($actor, 'price.customer.edit')
            || $rbac->hasPermission($actor, 'contract.create'),
            403
        );
    }

    private function assertVisible(Item $item, BusinessPartner $bp): void
    {
        $visible = $item->owning_bp_id === null || (int) $item->owning_bp_id === (int) $bp->id;
        abort_unless($visible, 404);
    }

    private function assertOwnedBy(Item $item, BusinessPartner $bp): void
    {
        abort_unless(
            $item->isBpOwned() && (int) $item->owning_bp_id === (int) $bp->id,
            403,
            '標準品目は編集できません。'
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Item>
     */
    private function requiredCandidates(int $bpId, ?int $excludeId = null)
    {
        return Item::query()
            ->where(function ($query) use ($bpId) {
                $query->whereNull('owning_bp_id')
                    ->orWhere('owning_bp_id', $bpId);
            })
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->orderBy('code')
            ->get();
    }
}
