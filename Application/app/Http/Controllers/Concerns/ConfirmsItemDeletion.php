<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsItemDeletion
{
    protected function issueItemDeleteConfirmationCode(Item $item): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->itemDeleteConfirmationKey($item), $code);

        return $code;
    }

    protected function assertItemDeleteConfirmation(Request $request, Item $item): void
    {
        $key = $this->itemDeleteConfirmationKey($item);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueItemDeleteConfirmationCode($item);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function itemDeleteConfirmationKey(Item $item): string
    {
        return 'item_delete_confirm.'.$item->id;
    }
}
