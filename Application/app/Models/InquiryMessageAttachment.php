<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class InquiryMessageAttachment extends Model
{
    protected $fillable = [
        'inquiry_message_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(InquiryMessage::class, 'inquiry_message_id');
    }

    public function isPreviewableImage(): bool
    {
        return in_array($this->mime_type, ['image/png', 'image/jpeg', 'image/gif'], true);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk('local')->exists($this->stored_path);
    }
}
