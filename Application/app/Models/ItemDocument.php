<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemDocument extends Model
{
    protected $fillable = [
        'item_id',
        'owning_bp_id',
        'title',
        'file_path',
        'original_name',
        'mime_type',
        'sort_order',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function contractDocuments(): HasMany
    {
        return $this->hasMany(ContractItemDocument::class);
    }
}
