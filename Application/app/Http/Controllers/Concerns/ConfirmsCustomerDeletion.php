<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsCustomerDeletion
{
    protected function issueCustomerDeleteConfirmationCode(Customer $customer): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->customerDeleteConfirmationKey($customer), $code);

        return $code;
    }

    protected function assertCustomerDeleteConfirmation(Request $request, Customer $customer): void
    {
        $key = $this->customerDeleteConfirmationKey($customer);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            // Re-issue so the user can retry from the detail page.
            $this->issueCustomerDeleteConfirmationCode($customer);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function customerDeleteConfirmationKey(Customer $customer): string
    {
        return 'customer_delete_confirm.'.$customer->id;
    }
}
