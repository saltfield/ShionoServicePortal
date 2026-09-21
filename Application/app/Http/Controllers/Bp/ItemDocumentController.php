<?php

namespace App\Http\Controllers\Bp;

use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\RbacService;
use App\Http\Controllers\Concerns\ConfirmsItemDocumentDeletion;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemDocumentController extends Controller
{
    use ConfirmsItemDocumentDeletion;

    public function store(Request $request, Item $item, ContractService $service, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        $this->assertVisible($item, $bp);

        $validated = $request->validate(
            [
                'title' => ['required', 'string', 'max:255'],
                'file' => [
                    'required',
                    'file',
                    'max:10240',
                    'extensions:'.implode(',', ContractService::ITEM_DOCUMENT_EXTENSIONS),
                ],
            ],
            [
                'file.extensions' => 'Documentテンプレートは Excel（.xls / .xlsx）、XML、HTML のみアップロードできます。',
            ]
        );

        try {
            $service->addItemDocument($actor, $item, $validated['title'], $request->file('file'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return back()->with('status', 'Documentテンプレートを追加しました。');
    }

    public function destroy(Request $request, ItemDocument $itemDocument, ContractService $service, RbacService $rbac): RedirectResponse
    {
        $actor = $request->user('bp');
        abort_unless($rbac->hasPermission($actor, 'contract.create'), 403);
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);
        abort_unless((int) $itemDocument->owning_bp_id === (int) $bp->id, 403, '標準テンプレートは削除できません。');

        $this->assertItemDocumentDeleteConfirmation($request, $itemDocument);

        try {
            $service->deleteItemDocument($actor, $itemDocument);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation_code' => $exception->getMessage()]);
        }

        return back()->with('status', 'Documentテンプレートを削除しました。発行済みPDFは契約側に残ります。');
    }

    public function download(Request $request, ItemDocument $itemDocument, RbacService $rbac): StreamedResponse
    {
        $actor = $request->user('bp');
        abort_unless(
            $rbac->hasPermission($actor, 'price.wholesale.edit')
            || $rbac->hasPermission($actor, 'price.customer.edit')
            || $rbac->hasPermission($actor, 'contract.create'),
            403
        );
        $bp = $actor->businessPartner;
        abort_unless($bp, 403);

        $visible = $itemDocument->owning_bp_id === null
            || (int) $itemDocument->owning_bp_id === (int) $bp->id;
        abort_unless($visible, 404);

        $itemDocument->loadMissing('item');
        $this->assertVisible($itemDocument->item, $bp);

        return Storage::disk('local')->download(
            $itemDocument->file_path,
            $itemDocument->original_name ?? $itemDocument->title
        );
    }

    private function assertVisible(Item $item, $bp): void
    {
        $visible = $item->owning_bp_id === null || (int) $item->owning_bp_id === (int) $bp->id;
        abort_unless($visible, 404);
    }
}
