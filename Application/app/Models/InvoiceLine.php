<?php

namespace App\Models;

use App\Domains\Catalog\Enums\BillingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'contract_item_id',
        'item_id',
        'description',
        'billing_type',
        'quantity',
        'unit_price',
        'tax_rate',
        'amount',
        'tax_amount',
        'amount_inclusive',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'tax_rate' => 'integer',
            'amount' => 'integer',
            'tax_amount' => 'integer',
            'amount_inclusive' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
