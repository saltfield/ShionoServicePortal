<?php

namespace App\Models;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Support\IdentifierNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessPartner extends Model
{
    /** @use HasFactory<\Database\Factories\BusinessPartnerFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'postal_code',
        'address',
        'building_name',
        'phone',
        'email',
        'parent_id',
        'depth',
        'two_factor_mode',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'two_factor_mode' => TwoFactorMode::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BusinessPartner $partner): void {
            if ($partner->code !== null) {
                $partner->code = IdentifierNormalizer::normalize($partner->code);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'managing_bp_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'bp_id');
    }
}
