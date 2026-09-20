<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyCondition extends Model
{
    protected $fillable = [
        'policy_id',
        'group_no',
        'attribute',
        'operator',
        'value_json',
    ];

    protected function casts(): array
    {
        return [
            'group_no' => 'integer',
            'value_json' => 'array',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
