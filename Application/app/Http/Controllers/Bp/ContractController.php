<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsContractDeletion;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Customer;
use App\Models\DataFieldName;
use App\Models\Item;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    use ConfirmsContractDeletion;

    public function index(Request $request, RbacService $rbac, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $ids = $hierarchy->descendantIdsIncludingSelf($actorBp);
        $contracts = Contract::query()
            ->with(['customer', 'owningBp', 'site'])
            ->whereIn('owning_bp_id', $ids)
            ->latest()
            ->paginate(20);

        return view('admin.contracts.index', [
            'contracts' => $contracts,
            'routePrefix' => 'bp',
        ]);
    }

    public function create(Request $request, RbacService $rbac, BpHierarchyService $hierarchy): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);
        $ids = $hierarchy->descendantIdsIncludingSelf($actorBp);

        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
        $cn = trim((string) $request->input('cn', ''));
        $customerName = trim((string) $request->input('customer_name', ''));
        $itemCode = trim((string) $request->input('item_code', ''));
        $itemName = trim((string) $request->input('item_name', ''));

        $selectedCustomer = null;
        if ($customerId) {
            $selectedCustomer = Customer::query()
                ->with('managingBp')
                ->whereKey($customerId)
                ->whereIn('managing_bp_id', $ids)
                ->first();
            abort_unless($selectedCustomer, 403);
        }

        $sites = collect();
        $selectedSite = null;
        if ($selectedCustomer) {
            $sites = Site::query()
                ->where('customer_id', $selectedCustomer->id)
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->get();
            $selectedSite = $siteId
                ? $sites->firstWhere('id', $siteId)
                : null;
        }

        $customers = null;
        if (! $selectedCustomer) {
            $customers = Customer::query()
                ->with('managingBp')
                ->whereIn('managing_bp_id', $ids)
                ->when($cn !== '', function ($query) use ($cn) {
                    $normalized = IdentifierNormalizer::normalize($cn);
                    $query->where('code', 'like', "%{$normalized}%");
                })
                ->when($customerName !== '', fn ($query) => $query->where('name', 'like', "%{$customerName}%"))
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString();
        }

        $items = collect();
        if ($selectedSite) {
            $items = Item::query()
                ->where('is_active', true)
                ->with('requiredItem')
                ->when($itemCode !== '', function ($query) use ($itemCode) {
                    $normalized = IdentifierNormalizer::normalize($itemCode);
                    $query->where('code', 'like', "%{$normalized}%");
                })
                ->when($itemName !== '', fn ($query) => $query->where('name', 'like', "%{$itemName}%"))
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString();
        }

        return view('admin.contracts.create', [
            'routePrefix' => 'bp',
            'filters' => [
                'cn' => $cn,
                'customer_name' => $customerName,
                'item_code' => $itemCode,
                'item_name' => $itemName,
            ],
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'items' => $items,
        ]);
    }

    public function store(Request $request, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:items,id'],
        ]);

        try {
            $site = Site::query()->findOrFail($validated['site_id']);
            $contract = $service->createDraft($request->user('bp'), $site, $validated['item_ids']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item_ids' => $exception->getMessage()]);
        }

        return redirect()->route('bp.contracts.show', $contract)->with('status', '契約下書きを作成しました。');
    }

    public function show(Request $request, Contract $contract, AuthorizationService $authorization): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        $contract->load([
            'customer', 'site', 'owningBp',
            'items.item.requiredItem', 'items.dataRows', 'items.documents',
            'statusHistories', 'applications',
        ]);

        return view('admin.contracts.show', [
            'contract' => $contract,
            'dataFieldNames' => DataFieldName::query()->where('is_active', true)->orderBy('name')->get(),
            'routePrefix' => 'bp',
            'deleteConfirmationCode' => $contract->status->value === 'draft'
                ? $this->issueContractDeleteConfirmationCode($contract)
                : null,
        ]);
    }

    public function destroy(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $this->assertContractDeleteConfirmation($request, $contract);

        try {
            $service->deleteDraft($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.index')
            ->with('status', '下書き契約を削除しました。');
    }

    public function updatePrices(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.partition_price' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $service->updateDraftPrices($request->user('bp'), $contract, $validated['prices']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['prices' => $exception->getMessage()]);
        }

        return back()->with('status', '価格を更新しました。');
    }

    public function submitApproval(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->submitPriceApproval($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return back()->with('status', '価格承認を申請しました。');
    }

    public function submitPriceChange(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.partition_price' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $service->submitPriceChange($request->user('bp'), $contract, $validated['prices']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['prices' => $exception->getMessage()]);
        }

        return back()->with('status', '価格変更を申請しました。');
    }

    public function activate(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->activate($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return back()->with('status', '契約を開通しました。');
    }

    public function regenerateDocuments(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->regenerateDocuments($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return back()->with('status', 'ドキュメントを再生成しました。');
    }

    public function upsertData(Request $request, ContractItem $contractItem, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*.data_field_name_id' => ['nullable', 'integer', 'exists:data_field_names,id'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.replace_code' => ['nullable', 'string', 'max:64'],
            'rows.*.value' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->upsertItemData($request->user('bp'), $contractItem, $validated['rows'] ?? []);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rows' => $exception->getMessage()]);
        }

        return back()->with('status', 'データを保存しました。');
    }

    public function downloadDocument(Request $request, ContractItem $contractItem, int $document, AuthorizationService $authorization): StreamedResponse
    {
        $contractItem->load('contract', 'documents');
        $authorization->authorize($request->user('bp'), 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contractItem->contract->owning_bp_id,
        ]);

        $doc = $contractItem->documents->firstWhere('id', $document);
        abort_unless($doc, 404);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name ?? $doc->title);
    }
}
