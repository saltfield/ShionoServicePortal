<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ContractItemDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsContractItemDocumentDeletion
{
    protected function issueContractItemDocumentDeleteConfirmationCode(ContractItemDocument $document): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->contractItemDocumentDeleteConfirmationKey($document), $code);

        return $code;
    }

    protected function assertContractItemDocumentDeleteConfirmation(Request $request, ContractItemDocument $document): void
    {
        $key = $this->contractItemDocumentDeleteConfirmationKey($document);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueContractItemDocumentDeleteConfirmationCode($document);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function contractItemDocumentDeleteConfirmationKey(ContractItemDocument $document): string
    {
        return 'contract_item_document_delete_confirm.'.$document->id;
    }
}
