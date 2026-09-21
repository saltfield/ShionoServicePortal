<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\DownloadsContractItemDocuments;
use App\Http\Controllers\Concerns\ResolvesContractShowTab;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\DataFieldName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    use DownloadsContractItemDocuments;
    use ResolvesContractShowTab;

    public function index(Request $request, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        abort_unless($actor->customer_id, 403);

        $contracts = Contract::query()
            ->with(['site', 'owningBp'])
            ->where('customer_id', $actor->customer_id)
            ->latest()
            ->paginate(20);

        return view('customer.contracts.index', compact('contracts'));
    }

    public function show(Request $request, Contract $contract, RbacService $rbac, ContractService $service): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        abort_unless((int) $contract->customer_id === (int) $actor->customer_id, 403);

        $contract->load([
            'customer', 'site', 'owningBp',
            'items.item', 'items.dataRows', 'items.documents',
            'statusHistories',
            'messages.user',
        ]);

        return view('admin.contracts.show', [
            'contract' => $contract,
            'dataFieldNames' => DataFieldName::query()->where('is_active', true)->orderBy('name')->get(),
            'routePrefix' => 'customer',
            'activeTab' => $this->resolveContractShowTab($request, true),
            'canPostMessages' => $service->canPostMessages($contract),
            'cancellationSuggestion' => null,
            'deleteConfirmationCode' => null,
            'documentDeleteCodes' => [],
            'documentFileMissing' => $this->contractDocumentFileMissingMap($contract->items),
        ]);
    }

    public function storeMessage(Request $request, Contract $contract, RbacService $rbac, ContractService $service): RedirectResponse
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        abort_unless((int) $contract->customer_id === (int) $actor->customer_id, 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $service->postMessage($actor, $contract, $validated['body']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.contracts.show', ['contract' => $contract, 'tab' => 'messages'])
            ->with('status', 'メッセージを投稿しました。');
    }

    public function downloadDocument(Request $request, ContractItem $contractItem, int $document): StreamedResponse
    {
        $actor = $request->user('customer');
        $contractItem->load('contract', 'documents');
        abort_unless((int) $contractItem->contract->customer_id === (int) $actor->customer_id, 403);
        abort_unless($contractItem->contract->status->value === 'activated', 403, '資料のダウンロードはサービス提供開始後に利用できます。');

        $doc = $contractItem->documents->firstWhere('id', $document);
        abort_unless($doc, 404);

        return $this->streamContractItemDocument($doc);
    }
}