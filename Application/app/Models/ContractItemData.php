<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItemData extends Model
{
    protected $table = 'contract_item_data';

    protected $fillable = [
        'contract_item_id',
        'data_field_name_id',
        'name',
        'replace_code',
        'value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            // APP_KEY 依存。DB 直読みは不可・値での SQL 検索は不可（画面・Eloquent 経由は平文）
            'value' => 'encrypted',
        ];
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function dataFieldName(): BelongsTo
    {
        return $this->belongsTo(DataFieldName::class);
    }
}
