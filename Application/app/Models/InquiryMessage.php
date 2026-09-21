<?php

namespace App\Models;

use App\Domains\Support\Enums\InquiryMessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InquiryMessage extends Model
{
    protected $fillable = [
        'inquiry_id',
        'user_id',
        'message_type',
        'body',
        'from_status',
        'to_status',
    ];

    protected function casts(): array
    {
        return [
            'message_type' => InquiryMessageType::class,
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(InquiryMessageAttachment::class);
    }

    public function isStatusChange(): bool
    {
        return $this->message_type === InquiryMessageType::StatusChange;
    }
}
