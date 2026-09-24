<?php

namespace App\Models;

use App\Domains\Billing\Enums\BillingBatchRunStatus;
use App\Domains\Billing\Enums\BillingBatchRunTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingBatchRun extends Model
{
    protected $fillable = [
        'billing_year_month',
        'trigger',
        'status',
        'actor_user_id',
        'invoices_count',
        'kickbacks_count',
        'skipped_count',
        'errors_count',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => BillingBatchRunTrigger::class,
            'status' => BillingBatchRunStatus::class,
            'invoices_count' => 'integer',
            'kickbacks_count' => 'integer',
            'skipped_count' => 'integer',
            'errors_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(BillingBatchError::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function kickbackInvoices(): HasMany
    {
        return $this->hasMany(KickbackInvoice::class);
    }
}
