<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingBatchError extends Model
{
    protected $fillable = [
        'billing_batch_run_id',
        'contract_id',
        'billing_year_month',
        'phase',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BillingBatchRun::class, 'billing_batch_run_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function phaseLabel(): string
    {
        return match ($this->phase) {
            'customer_invoice' => 'カスタマー請求',
            'kickback' => 'キックバック',
            default => $this->phase,
        };
    }
}
