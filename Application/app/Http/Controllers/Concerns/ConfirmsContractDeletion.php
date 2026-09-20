<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsContractDeletion
{
    protected function issueContractDeleteConfirmationCode(Contract $contract): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->contractDeleteConfirmationKey($contract), $code);

        return $code;
    }

    protected function assertContractDeleteConfirmation(Request $request, Contract $contract): void
    {
        $key = $this->contractDeleteConfirmationKey($contract);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueContractDeleteConfirmationCode($contract);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function contractDeleteConfirmationKey(Contract $contract): string
    {
        return 'contract_delete_confirm.'.$contract->id;
    }
}
