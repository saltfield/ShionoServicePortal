<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\AuthorizationService;
use App\Http\Controllers\Concerns\ConfirmsContractDeletion;
use App\Http\Controllers\Concerns\ConfirmsContractItemDocumentDeletion;
use App\Http\Controllers\Concerns\DownloadsContractItemDocuments;
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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    use ConfirmsContractDeletion;
    use ConfirmsContractItemDocumentDeletion;
    use DownloadsContractItemDocuments;
    use ResolvesContractShowTab;

    public function index(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'contract.view');

        $contracts = Contract::query()
            ->with(['customer', 'owningBp', 'site'])
            ->latest()
            ->paginate(20);

        return view('admin.contracts.index', [
            'contracts' => $contracts,
            'routePrefix' => 'admin',
        ]);
    }

    public function create(Request $request, AuthorizationService $authorization): View
    {
        $authorization->authorize($request->user('admin'), 'contract.create');

        $customerId = $request->filled('customer_id') ? (int) $request->input('customer_id') : null;
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
        $cn = trim((string) $request->input('cn', ''));
        $customerName = trim((string) $request->input('customer_name', ''));
        $itemCode = trim((string) $request->input('item_code', ''));
        $itemName = trim((string) $request->input('item_name', ''));

        $selectedCustomer = $customerId
            ? Customer::query()->with('managingBp')->find($customerId)
            : null;

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
            $managingBpId = (int) $selectedSite->customer->managing_bp_id;
            $items = Item::query()
                ->where('is_active', true)
                ->where(function ($query) use ($managingBpId) {
                    $query->whereNull('owning_bp_id')
                        ->orWhere('owning_bp_id', $managingBpId);
                })
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
            'routePrefix' => 'admin',
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
            'returnCustomerId' => $request->filled('return_customer_id') ? (int) $request->input('return_customer_id') : null,
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
            $contract = $service->createDraft($request->user('admin'), $site, $validated['item_ids']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['item_ids' => $exception->getMessage()]);
        }

        return redirect()->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract))
            ->with('status', '契約下書きを作成しました。');
    }

    public function show(Request $request, Contract $contract, AuthorizationService $authorization, ContractService $service): View
    {
        $authorization->authorize($request->user('admin'), 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);

        $contract->load([
            'customer', 'site', 'owningBp',
            'items.item.requiredItem', 'items.item.documents', 'items.dataRows', 'items.documents',
            'statusHistories', 'applications.fromBp', 'applications.toBp',
            'messages.user',
        ]);

        $activeTab = $this->resolveContractShowTab($request);

        $documentDeleteCodes = [];
        foreach ($contract->items as $line) {
            foreach ($line->documents as $document) {
                $documentDeleteCodes[$document->id] = $this->issueContractItemDocumentDeleteConfirmationCode($document);
            }
        }

        return view('admin.contracts.show', [
            'contract' => $contract,
            'dataFieldNames' => DataFieldName::query()->where('is_active', true)->orderBy('name')->get(),
            'routePrefix' => 'admin',
            'activeTab' => $activeTab,
            'canPostMessages' => $service->canPostMessages($contract),
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
            $service->deleteDraft($request->user('admin'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return $this->redirectAfterContractLeave($request, 'admin', $contract, '下書き契約を削除しました。');
    }

    public function updatePrices(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $validated = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'prices.*.partition_price' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $service->updateDraftPrices($request->user('admin'), $contract, $validated['prices']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['prices' => $exception->getMessage()]);
        }

        return back()->with('status', '価格を更新しました。');
    }

    public function submitApproval(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->submitPriceApproval($request->user('admin'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return back()->with('status', '価格承認を申請しました。');
    }

    public function activate(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        $firstBillingYearMonth = $this->resolveFirstBillingYearMonth($request);

        try {
            $service->activate($request->user('admin'), $contract, $firstBillingYearMonth);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['first_billing_mode' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', 'サービス提供開始にしました。');
    }

    public function revertService(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->revertServiceProvided($request->user('admin'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', '承認済に戻しました。');
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
                $request->user('admin'),
                $contract,
                $validated['final_billing_year_month'],
                (int) $validated['cancellation_amount'],
                $validated['cancellation_note'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cancellation_amount' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'overview']))
            ->with('status', '契約を解約しました。');
    }

    public function cancellationSuggestion(Request $request, Contract $contract, ContractService $service, AuthorizationService $authorization): JsonResponse
    {
        $authorization->authorize($request->user('admin'), 'contract.view', [
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
            $service->postMessage($request->user('admin'), $contract, $validated['body']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'messages']))
            ->with('status', 'メッセージを投稿しました。');
    }

    public function regenerateDocuments(Request $request, Contract $contract, ContractService $service): RedirectResponse
    {
        try {
            $service->regenerateDocuments($request->user('admin'), $contract);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contract' => $exception->getMessage()]);
        }

        $message = $contract->fresh()->status->value === 'approved'
            ? 'サンプル Document を生成しました。データタブの各品目「ドキュメント（PDF）」からダウンロードできます。'
            : 'ドキュメントを再生成しました。';

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contract, ['tab' => 'data']))
            ->with('status', $message);
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
            $service->upsertItemData($request->user('admin'), $contractItem, $validated['rows'] ?? []);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rows' => $exception->getMessage()]);
        }

        return back()->with('status', 'データを保存しました。');
    }

    public function downloadDocument(Request $request, ContractItem $contractItem, int $document, AuthorizationService $authorization): StreamedResponse
    {
        $contractItem->load('contract', 'documents');
        $authorization->authorize($request->user('admin'), 'contract.view', [
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
            $service->deleteContractItemDocument($request->user('admin'), $doc);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation_code' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.contracts.show', $this->contractShowRouteParams($request, $contractItem->contract, ['tab' => 'data']))
            ->with('status', 'ドキュメントを削除しました。');
    }
}
