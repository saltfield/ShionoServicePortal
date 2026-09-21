<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Concerns\ConfirmsItemDeletion;
use App\Http\Controllers\Concerns\ConfirmsItemDocumentDeletion;
use App\Http\Controllers\Controller;
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

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        $items = Item::query()
            ->with('requiredItem')
            ->whereNull('owning_bp_id')
            ->orderBy('code')
            ->paginate(20);

        return view('admin.items.index', [
            'items' => $items,
            'routePrefix' => 'admin',
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        return view('admin.items.create', [
            'routePrefix' => 'admin',
            'billingTypes' => BillingType::cases(),
            'requiredCandidates' => Item::query()->whereNull('owning_bp_id')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, CatalogPricingService $service): RedirectResponse
    {
        $validated = $this->validatedItem($request);

        try {
            $item = $service->createItem($request->user('admin'), $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['required_item_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.items.show', $item)
            ->with('status', "{$item->code} を作成しました。");
    }

    public function show(Request $request, Item $item, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');
        $item->load(['requiredItem', 'documents']);

        return view('admin.items.show', [
            'item' => $item,
            'routePrefix' => 'admin',
            'canManageItem' => true,
            'canManageDocuments' => true,
            'deleteConfirmationCode' => $this->issueItemDeleteConfirmationCode($item),
            'documentDeleteCodes' => $item->documents
                ->mapWithKeys(fn ($document) => [
                    $document->id => $this->issueItemDocumentDeleteConfirmationCode($document),
                ])
                ->all(),
        ]);
    }

    public function edit(Request $request, Item $item, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        return view('admin.items.edit', [
            'item' => $item,
            'routePrefix' => 'admin',
            'billingTypes' => BillingType::cases(),
            'requiredCandidates' => Item::query()
                ->whereNull('owning_bp_id')
                ->whereKeyNot($item->id)
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function update(Request $request, Item $item, CatalogPricingService $service): RedirectResponse
    {
        $validated = $this->validatedItem($request);

        try {
            $service->updateItem($request->user('admin'), $item, $validated);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['required_item_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.items.show', $item)
            ->with('status', '品目を更新しました。');
    }

    public function destroy(Request $request, Item $item, CatalogPricingService $service): RedirectResponse
    {
        $this->assertItemDeleteConfirmation($request, $item);

        try {
            $service->deleteItem($request->user('admin'), $item);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.items.index')
            ->with('status', '品目を削除しました。');
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
}
