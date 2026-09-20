<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsInquiryDeletion
{
    protected function issueInquiryDeleteConfirmationCode(Inquiry $inquiry): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->inquiryDeleteConfirmationKey($inquiry), $code);

        return $code;
    }

    protected function assertInquiryDeleteConfirmation(Request $request, Inquiry $inquiry): void
    {
        $key = $this->inquiryDeleteConfirmationKey($inquiry);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueInquiryDeleteConfirmationCode($inquiry);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function inquiryDeleteConfirmationKey(Inquiry $inquiry): string
    {
        return 'inquiry_delete_confirm.'.$inquiry->id;
    }
}
