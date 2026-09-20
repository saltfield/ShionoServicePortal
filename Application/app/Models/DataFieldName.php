<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataFieldName extends Model
{
    protected $fillable = [
        'name',
        'replace_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function contractItemData(): HasMany
    {
        return $this->hasMany(ContractItemData::class);
    }
}
