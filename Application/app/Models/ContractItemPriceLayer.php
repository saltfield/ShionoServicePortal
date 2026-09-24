<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItemPriceLayer extends Model
{
    protected $fillable = [
        'contract_item_id',
        'seller_bp_id',
        'buyer_bp_id',
        'amount',
        'depth_from_root',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'depth_from_root' => 'integer',
        ];
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function sellerBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'seller_bp_id');
    }

    public function buyerBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'buyer_bp_id');
    }
}
