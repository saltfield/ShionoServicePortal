<?php

namespace App\Http\Controllers\Customer;

use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
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

    public function show(Request $request, Contract $contract, RbacService $rbac): View
    {
        $actor = $request->user('customer');
        abort_unless($rbac->hasPermission($actor, 'contract.view'), 403);
        abort_unless((int) $contract->customer_id === (int) $actor->customer_id, 403);

        $contract->load([
            'site', 'owningBp',
            'items.item', 'items.dataRows', 'items.documents',
        ]);

        return view('customer.contracts.show', compact('contract'));
    }

    public function downloadDocument(Request $request, ContractItem $contractItem, int $document): StreamedResponse
    {
        $actor = $request->user('customer');
        $contractItem->load('contract', 'documents');
        abort_unless((int) $contractItem->contract->customer_id === (int) $actor->customer_id, 403);

        $doc = $contractItem->documents->firstWhere('id', $document);
        abort_unless($doc, 404);

        return Storage::disk('local')->download($doc->file_path, $doc->original_name ?? $doc->title);
    }
}
