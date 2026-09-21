<?php

namespace App\Models;

use App\Domains\Support\Enums\InquiryAssigneeType;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Enums\InquiryVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inquiry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'assignee_type',
        'assignee_bp_id',
        'subject',
        'status',
        'visibility',
        'opened_by_user_id',
        'issuer_bp_id',
        'customer_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'assignee_type' => InquiryAssigneeType::class,
            'status' => InquiryStatus::class,
            'visibility' => InquiryVisibility::class,
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

    public function assigneeBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'assignee_bp_id');
    }

    public function issuerBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'issuer_bp_id');
    }

    /** @deprecated use assigneeBp */
    public function owningBp(): BelongsTo
    {
        return $this->assigneeBp();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class)->orderBy('created_at');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(InquiryRead::class);
    }

    public function issuerLabel(): string
    {
        if ($this->issuerBp !== null) {
            $label = $this->issuerBp->code.' / '.$this->issuerBp->name;
            if ($this->customer !== null) {
                $label .= '（'.$this->customer->code.' / '.$this->customer->name.'）';
            }

            return $label;
        }

        if ($this->customer !== null) {
            return $this->customer->code.' / '.$this->customer->name;
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    public function toAbacAttributes(): array
    {
        return [
            'resource_type' => 'inquiry',
            'owner_bp_id' => $this->assignee_bp_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status->value,
        ];
    }
}
