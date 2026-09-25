<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Catalog\Support\ItemTypeOptions;
use App\Domains\Catalog\Support\OrderCatalogScope;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsContractDeletion;
use App\Http\Controllers\Concerns\ConfirmsContractItemDocumentDeletion;
use App\Http\Controllers\Concerns\DownloadsContractItemDocuments;
use App\Http\Controllers\Concerns\FiltersContractsIndex;
use App\Http\Controllers\Concerns\OrdersContractIndexQuery;
use App\Http\Controllers\Concerns\ResolvesContractShowTab;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\ContractItemDocument;
use App\Models\Customer;
use App\Models\DataFieldName;
use App\Models\Item;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    use ConfirmsContractDeletion;
    use ConfirmsContractItemDocumentDeletion;
    use DownloadsContractItemDocuments;
    use FiltersContractsIndex;
    use OrdersContractIndexQuery;
    use ResolvesContractShowTab;

    public function index(Request $request, RbacService $rbac, BpHierarchyService $hierarchy, ContractService $service): View
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        $actorBp = $actor->businessPartner;
        abort_unless($actorBp, 403);

        $status = $this->validatedStatusFilter($request);
        $unreadOnly = $request->boolean('unread_messages');
        $filters = $this->contractIndexFilters($request);
        $ids = $hierarchy->descendantIdsIncludingSelf($actorBp);
        $contracts = Contract::query()
            ->with(['customer', 'owningBp', 'site'])
            ->whereIn('owning_bp_id', $ids)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($unreadOnly, function ($query) use ($service, $actor, $ids) {
                $unreadIds = $service->unreadMessageContractIds($actor, $ids);
                $query->whereIn('id', $unreadIds === [] ? [0] : $unreadIds);
            });

        $contracts = $this->applyContractIndexFilters($contracts, $filters);
        $contracts = $this->orderContractIndexQuery($contracts, $status)
            ->paginate(20)
            ->withQueryString();

        return view('admin.contracts.index', [
            'contracts' => $contracts,
            'routePrefix' => 'bp',
            'statusFilter' => $status,
            'unreadMessagesFilter' => $unreadOnly,
            'filters' => $filters,
        ]);
    }

    private function validatedStatusFilter(Request $request): ?string
    {
        $status = $request->query('status');
        if ($status === null || $status === '') {
            return null;
        }

        $request->validate([
            'status' => ['required', Rule::enum(ContractStatus::class)],
        ]);

        return (string) $status;
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
        $itemTypeId = $request->filled('item_type_id') ? (int) $request->input('item_type_id') : null;
        $billingType = trim((string) $request->input('billing_type', ''));
        if ($billingType !== '' && BillingType::tryFrom($billingType) === null) {
            $billingType = '';
        }

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
        $standardPartitions = [];
        $customerAmounts = [];
        $itemTypeOptions = collect();
        if ($selectedSite) {
            $managingBp = $selectedCustomer?->managingBp;
            abort_unless($managingBp, 404);

            $ownerBpIds = OrderCatalogScope::ownerBpIds($actor, $managingBp, $hierarchy);
            $itemTypeOptions = ItemTypeOptions::selectableForOwnerIds($ownerBpIds);
            $items = OrderCatalogScope::itemsQuery($ownerBpIds)
                ->with(['requiredItem', 'itemType'])
                ->when($itemCode !== '', function ($query) use ($itemCode) {
                    $normalized = IdentifierNormalizer::normalize($itemCode);
                    $query->where('code', 'like', "%{$normalized}%");
                })
                ->when($itemName !== '', fn ($query) => $query->where('name', 'like', "%{$itemName}%"))
                ->when($itemTypeId !== null, fn ($query) => $query->where('item_type_id', $itemTypeId))
                ->when($billingType !== '', fn ($query) => $query->where('billing_type', $billingType))
                ->orderByRaw('CASE WHEN owning_bp_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString();

            $owningBp = $managingBp;
            $parentBp = $owningBp->parent;
            $pricing = app(CatalogPricingService::class);
            foreach ($items as $item) {
                $standardPartitions[$item->id] = (int) ($parentBp
                    ? $pricing->resolveWholesaleAmount($item, $parentBp, $owningBp)
                    : $item->partition_price);
                $customerAmounts[$item->id] = (int) $pricing->resolveCustomerAmount($item, $selectedCustomer, $owningBp);
            }
        }

        return view('admin.contracts.create', [
            'routePrefix' => 'bp',
            'filters' => [
                'cn' => $cn,
                'customer_name' => $customerName,
                'item_code' => $itemCode,
                'item_name' => $itemName,
                'item_type_id' => $itemTypeId,
                'billing_type' => $billingType,
            ],
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'items' => $items,
            'itemTypeOptions' => $itemTypeOptions,
            'billingTypes' => BillingType::cases(),
            'standardPartitions' => $standardPartitions,
            'customerAmounts' => $customerAmounts,
            'returnCustomerId' => $request->filled('return_customer_id') ? (int) $request->input('return_customer_id') : null,
        ]);
    }

    public function store(Request $request, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:items,id'],
            'special_price_requested' => ['nullable', 'boolean'],
            'special_price_reason' => ['nullable', 'string', 'max:2000', 'required_if:special_price_requested,1'],
            'partitions' => ['nullable', 'array'],
            'partitions.*' => ['nullable', 'integer', 'min:0'],
            'unit_prices' => ['nullable', 'array'],
            'unit_prices.*' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $site = Site::query()->findOrFail($validated['site_id']);
            $contract = $service->createDraft(
                $request->user('bp'),
                $site,
                $validated['item_ids'],
                [
                    'special_price_requested' => $request->boolean('special_price_requested'),
                    'special_price_reason' => $validated['special_price_reason'] ?? null,
                    'partitions' => $validated['partitions'] ?? [],
                    'unit_prices' => $validated['unit_prices'] ?? [],
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item_ids' => $exception->getMessage()]);
        }

        $statusMessage = $contract->special_price_requested
            ? 'オーダーを作成し、特価申請を送信しました。'
            : 'オーダーを作成しました。';

        return redirect()->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract))
            ->with('status', $statusMessage);
    }

    public function show(Request $request, Contract $contract, AuthorizationService $authorization, ContractService $service, RbacService $rbac): View
    {
        $actor = $request->user('bp');
        $authorization->authorize($actor, 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        $contract->load([
            'customer', 'site', 'owningBp',
            'items.item.requiredItem', 'items.item.documents', 'items.dataRows', 'items.documents',
            'dataRows',
            'statusHistories', 'applications.fromBp', 'applications.toBp',
            'messages.user',
        ]);

        $documentDeleteCodes = [];
        foreach ($contract->items as $line) {
            foreach ($line->documents as $document) {
                $documentDeleteCodes[$document->id] = $this->issueContractItemDocumentDeleteConfirmationCode($document);
            }
        }

        return view('admin.contracts.show', [
            'contract' => $contract,
            'dataFieldNames' => DataFieldName::query()->where('is_active', true)->orderBy('name')->get(),
            'routePrefix' => 'bp',
            'activeTab' => $this->resolveContractShowTab($request),
            'canPostMessages' => $service->canPostMessages($contract),
            'canManageBilling' => $rbac->hasPermission($actor, 'invoice.manage'),
            'cancellationSuggestion' => $this->defaultCancellationSuggestion($contract, $service),
            'deleteConfirmationCode' => $contract->status->value === 'draft'
                ? $this->issueContractDeleteConfirmationCode($contract)
                : null,
            'documentDeleteCodes' => $documentDeleteCodes,
            'documentFileMissing' => $this->contractDocumentFileMissingMap($contract->items),
            'returnCustomerId' => $this->resolveReturnCustomerId($request, $contract),
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

        return $this->redirectAfterContractLeave($request, 'bp', $contract, 'オーダー作成中の契約を削除しました。');
    }

    public function updatePrices(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.partition_price' => ['nullable', 'integer', 'min:0'],
            'special_price_requested' => ['nullable', 'boolean'],
            'special_price_reason' => ['nullable', 'string', 'max:2000', 'required_if:special_price_requested,1'],
        ]);

        try {
            $service->updateDraftPrices(
                $request->user('bp'),
                $contract,
                $validated['prices'],
                [
                    'special_price_requested' => $request->boolean('special_price_requested'),
                    'special_price_reason' => $validated['special_price_reason'] ?? null,
                ],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['prices' => $exception->getMessage()]);
        }

        return back()->with('status', '価格を更新しました。');
    }

    public function updateBilling(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'auto_invoice_enabled' => ['nullable', 'boolean'],
            'billing_suspended' => ['nullable', 'boolean'],
            'end_user_billing_disabled' => ['nullable', 'boolean'],
            'bill_initial_in_system' => ['nullable', 'boolean'],
            'recalc_on_price_change' => ['nullable', 'boolean'],
            'kickback_start_year_month' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'items' => ['nullable', 'array'],
            'items.*.bill_initial_in_system' => ['nullable'],
            'items.*.end_user_billing_disabled' => ['nullable'],
        ]);

        $payload = [
            'auto_invoice_enabled' => $request->boolean('auto_invoice_enabled'),
            'billing_suspended' => $request->boolean('billing_suspended'),
            'end_user_billing_disabled' => $request->boolean('end_user_billing_disabled'),
            'bill_initial_in_system' => $request->boolean('bill_initial_in_system'),
            'recalc_on_price_change' => $request->boolean('recalc_on_price_change'),
            'kickback_start_year_month' => $validated['kickback_start_year_month'] ?? null,
            'items' => [],
        ];

        foreach ($validated['items'] ?? [] as $itemId => $row) {
            $payload['items'][(int) $itemId] = [
                'bill_initial_in_system' => array_key_exists('bill_initial_in_system', $row) && $row['bill_initial_in_system'] !== ''
                    ? filter_var($row['bill_initial_in_system'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'end_user_billing_disabled' => array_key_exists('end_user_billing_disabled', $row) && $row['end_user_billing_disabled'] !== ''
                    ? filter_var($row['end_user_billing_disabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
            ];
        }

        try {
            $service->updateBillingSettings($request->user('bp'), $contract, $payload);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['billing' => $exception->getMessage()]);
        }

        return $this->redirectToContractShow($request, 'bp', $contract, 'billing', '請求設定を更新しました。');
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
        $firstBillingYearMonth = $this->resolveFirstBillingYearMonth($request);

        try {
            $service->activate($request->user('bp'), $contract, $firstBillingYearMonth);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['first_billing_mode' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', 'サービス提供開始にしました。');
    }

    public function revertService(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->revertServiceProvided($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', '承認済・手配中に戻しました。');
    }

    public function cancel(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'final_billing_year_month' => ['required', 'string'],
            'cancellation_amount' => ['required', 'integer', 'min:0'],
            'cancellation_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->cancel(
                $request->user('bp'),
                $contract,
                $validated['final_billing_year_month'],
                (int) $validated['cancellation_amount'],
                $validated['cancellation_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cancellation_amount' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', '契約を解約しました。');
    }

    public function cancellationSuggestion(Request $request, Contract $contract, ContractService $service, AuthorizationService $authorization): JsonResponse
    {
        $authorization->authorize($request->user('bp'), 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        $validated = $request->validate([
            'final_billing_year_month' => ['required', 'string'],
        ]);

        try {
            return response()->json($service->suggestCancellationAmount($contract, $validated['final_billing_year_month']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['final_billing_year_month' => $exception->getMessage()]);
        }
    }

    public function storeMessage(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $service->postMessage($request->user('bp'), $contract, $validated['body']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'messages']))
            ->with('status', 'メッセージを投稿しました。');
    }

    public function regenerateDocuments(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->regenerateDocuments($request->user('bp'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        $message = $contract->fresh()->status->value === 'approved'
            ? 'サンプル Document を生成しました。データタブの各品目「ドキュメント（PDF）」からダウンロードできます。'
            : 'ドキュメントを再生成しました。';

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'data']))
            ->with('status', $message);
    }

    public function upsertContractData(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['nullable', 'array', 'max:'.ContractService::MAX_DATA_ROWS],
            'rows.*.data_field_name_id' => ['nullable', 'integer', 'exists:data_field_names,id'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.replace_code' => ['nullable', 'string', 'max:64'],
            'rows.*.value' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->upsertContractData($request->user('bp'), $contract, $validated['rows'] ?? []);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rows' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'data']))
            ->with('status', '契約共通データを保存しました。');
    }

    public function upsertData(Request $request, ContractItem $contractItem, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['nullable', 'array', 'max:'.ContractService::MAX_DATA_ROWS],
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

        return $this->streamContractItemDocument($doc);
    }

    public function destroyDocument(
        Request $request,
        ContractItem $contractItem,
        int $document,
        ContractService $service,
    ): RedirectResponse {
        $contractItem->load('contract', 'documents');
        $doc = $contractItem->documents->firstWhere('id', $document);
        abort_unless($doc instanceof ContractItemDocument, 404);

        $this->assertContractItemDocumentDeleteConfirmation($request, $doc);

        try {
            $service->deleteContractItemDocument($request->user('bp'), $doc);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation_code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('bp.contracts.show', $this->contractShowRouteParams($request, $contractItem->contract, ['tab' => 'data']))
            ->with('status', 'ドキュメントを削除しました。');
    }
}
