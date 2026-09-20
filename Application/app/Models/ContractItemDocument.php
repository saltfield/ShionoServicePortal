<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItemDocument extends Model
{
    protected $fillable = [
        'contract_item_id',
        'item_document_id',
        'title',
        'file_path',
        'original_name',
        'mime_type',
    ];

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function itemDocument(): BelongsTo
    {
        return $this->belongsTo(ItemDocument::class);
    }
}
