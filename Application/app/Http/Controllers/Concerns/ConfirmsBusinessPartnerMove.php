<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BusinessPartner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsBusinessPartnerMove
{
    protected function issueBusinessPartnerMoveConfirmationCode(BusinessPartner $partner): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->businessPartnerMoveConfirmationKey($partner), $code);

        return $code;
    }

    protected function assertBusinessPartnerMoveConfirmation(Request $request, BusinessPartner $partner): void
    {
        $key = $this->businessPartnerMoveConfirmationKey($partner);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueBusinessPartnerMoveConfirmationCode($partner);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function businessPartnerMoveConfirmationKey(BusinessPartner $partner): string
    {
        return 'bp_move_confirm.'.$partner->id;
    }
}
