<?php

namespace App\Models;

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Auth\Support\IdentifierNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KickbackInvoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'contract_id',
        'billing_batch_run_id',
        'from_bp_id',
        'to_bp_id',
        'billing_year_month',
        'due_year_month',
        'status',
        'subtotal',
        'tax_total',
        'total',
        'issued_at',
        'paid_at',
        'withdrawn_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (KickbackInvoice $invoice): void {
            if ($invoice->code !== null) {
                $invoice->code = IdentifierNormalizer::normalize($invoice->code);
            }
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function batchRun(): BelongsTo
    {
        return $this->belongsTo(BillingBatchRun::class, 'billing_batch_run_id');
    }

    public function fromBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'from_bp_id');
    }

    public function toBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'to_bp_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KickbackInvoiceLine::class)->orderBy('sort_order');
    }
}
