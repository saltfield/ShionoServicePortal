<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractItem extends Model
{
    protected $fillable = [
        'contract_id',
        'item_id',
        'unit_price',
        'partition_price',
        'standard_partition_price',
        'tax_rate',
        'price_locked',
        'bill_initial_in_system',
        'end_user_billing_disabled',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'partition_price' => 'integer',
            'standard_partition_price' => 'integer',
            'tax_rate' => 'integer',
            'price_locked' => 'boolean',
        ];
    }

    public function partitionDiffFromStandard(): ?int
    {
        if ($this->standard_partition_price === null) {
            return null;
        }

        return (int) $this->standard_partition_price - (int) $this->partition_price;
    }

    public function getBillInitialInSystemAttribute(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    public function getEndUserBillingDisabledAttribute(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function dataRows(): HasMany
    {
        return $this->hasMany(ContractItemData::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractItemDocument::class);
    }

    public function priceLayers(): HasMany
    {
        return $this->hasMany(ContractItemPriceLayer::class)->orderBy('depth_from_root');
    }

    public function effectiveBillInitialInSystem(): bool
    {
        if ($this->bill_initial_in_system !== null) {
            return (bool) $this->bill_initial_in_system;
        }

        return (bool) ($this->contract?->bill_initial_in_system ?? true);
    }

    public function effectiveEndUserBillingDisabled(): bool
    {
        if ($this->end_user_billing_disabled !== null) {
            return (bool) $this->end_user_billing_disabled;
        }

        return (bool) ($this->contract?->end_user_billing_disabled ?? false);
    }
}
