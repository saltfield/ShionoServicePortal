<?php

namespace App\Models;

use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Catalog\Enums\BillingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    /** @use HasFactory<\Database\Factories\ItemFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'billing_type',
        'required_item_id',
        'partition_price',
        'recommended_price',
        'user_price',
        'tax_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'partition_price' => 'integer',
            'recommended_price' => 'integer',
            'user_price' => 'integer',
            'tax_rate' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Item $item): void {
            if ($item->code !== null) {
                $item->code = IdentifierNormalizer::normalize($item->code);
            }
        });
    }

    public function requiredItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'required_item_id');
    }

    public function requiredByItems(): HasMany
    {
        return $this->hasMany(Item::class, 'required_item_id');
    }

    public function wholesalePrices(): HasMany
    {
        return $this->hasMany(BpWholesalePrice::class);
    }

    public function customerPrices(): HasMany
    {
        return $this->hasMany(CustomerPrice::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ItemDocument::class)->orderBy('sort_order');
    }
}
