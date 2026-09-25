<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KickbackInvoiceLine extends Model
{
    protected $fillable = [
        'kickback_invoice_id',
        'contract_item_id',
        'item_id',
        'seller_bp_id',
        'buyer_bp_id',
        'description',
        'upper_amount',
        'partition_amount',
        'amount',
        'tax_rate',
        'tax_amount',
        'amount_inclusive',
        'sort_order',
        'is_adjustment',
    ];

    protected function casts(): array
    {
        return [
            'upper_amount' => 'integer',
            'partition_amount' => 'integer',
            'amount' => 'integer',
            'tax_rate' => 'integer',
            'tax_amount' => 'integer',
            'amount_inclusive' => 'integer',
            'sort_order' => 'integer',
            'is_adjustment' => 'boolean',
        ];
    }

    public function kickbackInvoice(): BelongsTo
    {
        return $this->belongsTo(KickbackInvoice::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
