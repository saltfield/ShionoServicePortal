<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpWholesalePrice extends Model
{
    protected $fillable = [
        'item_id',
        'seller_bp_id',
        'buyer_bp_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
