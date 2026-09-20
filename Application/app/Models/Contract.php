<?php

namespace App\Models;

use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Contract\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'site_id',
        'customer_id',
        'owning_bp_id',
        'status',
        'applied_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'applied_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contract $contract): void {
            if ($contract->code !== null) {
                $contract->code = IdentifierNormalizer::normalize($contract->code);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ContractStatusHistory::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
