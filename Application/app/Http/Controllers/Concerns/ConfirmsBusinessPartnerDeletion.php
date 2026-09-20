<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BusinessPartner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsBusinessPartnerDeletion
{
    protected function issueBusinessPartnerDeleteConfirmationCode(BusinessPartner $partner): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->businessPartnerDeleteConfirmationKey($partner), $code);

        return $code;
    }

    protected function assertBusinessPartnerDeleteConfirmation(Request $request, BusinessPartner $partner): void
    {
        $key = $this->businessPartnerDeleteConfirmationKey($partner);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueBusinessPartnerDeleteConfirmationCode($partner);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function businessPartnerDeleteConfirmationKey(BusinessPartner $partner): string
    {
        return 'bp_delete_confirm.'.$partner->id;
    }
}
