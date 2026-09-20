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
        'tax_rate',
        'price_locked',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'partition_price' => 'integer',
            'tax_rate' => 'integer',
            'price_locked' => 'boolean',
        ];
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
}
