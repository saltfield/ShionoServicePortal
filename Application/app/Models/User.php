<?php

namespace App\Models;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Support\IdentifierNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'login_id',
        'password',
        'user_type',
        'bp_id',
        'customer_id',
        'name',
        'email',
        'two_factor_secret',
        'two_factor_mode',
        'two_factor_confirmed_at',
        'two_factor_forced_disabled',
        'must_change_password',
        'password_changed_at',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'two_factor_mode' => TwoFactorMode::class,
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_forced_disabled' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->login_id !== null) {
                $user->login_id = IdentifierNormalizer::normalize($user->login_id);
            }
        });
    }

    public function businessPartner(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'bp_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withPivot(['scope_type', 'scope_id'])
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->user_type === UserType::Admin;
    }

    public function isBp(): bool
    {
        return $this->user_type === UserType::Bp;
    }

    public function isCustomer(): bool
    {
        return $this->user_type === UserType::Customer;
    }
}
