<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const BUILTIN_CODES = [
        'system_admin',
        'bp_owner',
        'bp_sales',
        'bp_support',
        'customer_owner',
        'customer_member',
    ];

    protected $fillable = [
        'code',
        'name',
        'scope',
        'description',
    ];

    public static function isBuiltinCode(string $code): bool
    {
        return in_array($code, self::BUILTIN_CODES, true);
    }

    public function isBuiltin(): bool
    {
        return self::isBuiltinCode($this->code);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role')
            ->withPivot(['scope_type', 'scope_id'])
            ->withTimestamps();
    }
}
