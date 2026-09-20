<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Policy extends Model
{
    protected $fillable = [
        'code',
        'name',
        'effect',
        'resource',
        'action',
        'priority',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(PolicyCondition::class);
    }
}
