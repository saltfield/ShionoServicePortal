<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemType extends Model
{
    protected $fillable = [
        'name',
        'message',
        'is_active',
        'owning_bp_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function isSystemType(): bool
    {
        return $this->owning_bp_id === null;
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function hasGuideMessage(): bool
    {
        return trim((string) $this->message) !== '';
    }
}
