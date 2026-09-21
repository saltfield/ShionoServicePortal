<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Auth\Enums\UserType;
use App\Domains\Support\Services\InquiryService;
use App\Models\Inquiry;
use App\Models\InquiryMessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait DownloadsInquiryAttachment
{
    protected function streamInquiryAttachment(
        User $actor,
        Inquiry $inquiry,
        InquiryMessageAttachment $attachment,
        InquiryService $service,
    ): StreamedResponse {
        if ($actor->user_type === UserType::Admin) {
            $service->assertAdminInspectable($actor, $inquiry);
        } else {
            $service->assertVisible($actor, $inquiry);
        }

        $attachment->loadMissing('message');
        abort_unless(
            $attachment->message !== null && (int) $attachment->message->inquiry_id === (int) $inquiry->id,
            404,
        );
        abort_unless($attachment->existsOnDisk(), 404);

        $disposition = $attachment->isPreviewableImage() ? 'inline' : 'attachment';

        return Storage::disk('local')->response(
            $attachment->stored_path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.$attachment->original_name.'"',
            ],
        );
    }
}
