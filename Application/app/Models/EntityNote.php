<?php

namespace App\Models;

use App\Domains\Contract\Enums\ContractNoteVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityNote extends Model
{
    protected $table = 'entity_notes';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'visibility',
        'owner_type',
        'owner_id',
        'body',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visibility' => ContractNoteVisibility::class,
            'owner_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
