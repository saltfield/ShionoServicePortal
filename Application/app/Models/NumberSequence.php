<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    protected $fillable = [
        'prefix',
        'year_month',
        'last_seq',
    ];

    protected function casts(): array
    {
        return [
            'last_seq' => 'integer',
        ];
    }
}
