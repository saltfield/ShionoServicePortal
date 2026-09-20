<?php

namespace App\Models;

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Support\IdentifierNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'managing_bp_id',
        'name',
        'name_kana',
        'postal_code',
        'address',
        'building_name',
        'phone',
        'email',
        'entity_type',
        'two_factor_mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => EntityType::class,
            'two_factor_mode' => TwoFactorMode::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->code !== null) {
                $customer->code = IdentifierNormalizer::normalize($customer->code);
            }
        });
    }

    public function managingBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'managing_bp_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'customer_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
