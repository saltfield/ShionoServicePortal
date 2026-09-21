<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Services\AuthorizationService;
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
    public function store(Request $request, Item $item, ContractService $service): RedirectResponse
    {
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
            $service->addItemDocument($request->user('admin'), $item, $validated['title'], $request->file('file'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return back()->with('status', 'Documentテンプレートを追加しました。');
    }

    public function destroy(Request $request, ItemDocument $itemDocument, ContractService $service, AuthorizationService $authorization): RedirectResponse
    {
        $authorization->authorize($request->user('admin'), 'item.manage');
        $this->assertItemDocumentDeleteConfirmation($request, $itemDocument);

        try {
            $service->deleteItemDocument($request->user('admin'), $itemDocument);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['confirmation_code' => $exception->getMessage()]);
        }

        return back()->with('status', 'Documentテンプレートを削除しました。発行済みPDFは契約側に残ります。');
    }

    public function download(Request $request, ItemDocument $itemDocument, AuthorizationService $authorization): StreamedResponse
    {
        $authorization->authorize($request->user('admin'), 'item.manage');

        return Storage::disk('local')->download(
            $itemDocument->file_path,
            $itemDocument->original_name ?? $itemDocument->title
        );
    }
}
