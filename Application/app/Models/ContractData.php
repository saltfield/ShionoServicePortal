<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractData extends Model
{
    protected $table = 'contract_data';

    protected $fillable = [
        'contract_id',
        'data_field_name_id',
        'name',
        'replace_code',
        'value',
        'sort_order',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function dataFieldName(): BelongsTo
    {
        return $this->belongsTo(DataFieldName::class);
    }
}
