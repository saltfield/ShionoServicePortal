<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ItemDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsItemDocumentDeletion
{
    protected function issueItemDocumentDeleteConfirmationCode(ItemDocument $document): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->itemDocumentDeleteConfirmationKey($document), $code);

        return $code;
    }

    protected function assertItemDocumentDeleteConfirmation(Request $request, ItemDocument $document): void
    {
        $key = $this->itemDocumentDeleteConfirmationKey($document);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueItemDocumentDeleteConfirmationCode($document);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function itemDocumentDeleteConfirmationKey(ItemDocument $document): string
    {
        return 'item_document_delete_confirm.'.$document->id;
    }
}
