<?php

namespace App\Models;

use App\Domains\Support\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inquiry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'subject',
        'status',
        'opened_by_user_id',
        'customer_id',
        'owning_bp_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InquiryStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class)->orderBy('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAbacAttributes(): array
    {
        return [
            'resource_type' => 'inquiry',
            'owner_bp_id' => $this->owning_bp_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status->value,
        ];
    }
}
