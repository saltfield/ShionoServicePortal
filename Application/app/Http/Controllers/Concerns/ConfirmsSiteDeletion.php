<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ConfirmsSiteDeletion
{
    protected function issueSiteDeleteConfirmationCode(Site $site): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        session()->put($this->siteDeleteConfirmationKey($site), $code);

        return $code;
    }

    protected function assertSiteDeleteConfirmation(Request $request, Site $site): void
    {
        $key = $this->siteDeleteConfirmationKey($site);
        $expected = session()->pull($key);
        $input = (string) $request->input('confirmation_code', '');

        if ($expected === null || $input !== $expected) {
            $this->issueSiteDeleteConfirmationCode($site);

            throw ValidationException::withMessages([
                'confirmation_code' => '確認コードが一致しません。もう一度お試しください。',
            ]);
        }
    }

    private function siteDeleteConfirmationKey(Site $site): string
    {
        return 'site_delete_confirm.'.$site->id;
    }
}
