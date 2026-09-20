<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsAnnouncementDeletion
{
    protected function issueAnnouncementDeleteConfirmationCode(Announcement $announcement): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->announcementDeleteConfirmationKey($announcement), $code);

        return $code;
    }

    protected function assertAnnouncementDeleteConfirmation(Request $request, Announcement $announcement): void
    {
        $key = $this->announcementDeleteConfirmationKey($announcement);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueAnnouncementDeleteConfirmationCode($announcement);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function announcementDeleteConfirmationKey(Announcement $announcement): string
    {
        return 'announcement_delete_confirm.'.$announcement->id;
    }
}
