<?php

namespace App\Models;

use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'contract_id',
        'customer_id',
        'owning_bp_id',
        'issuer_bp_id',
        'source',
        'billing_batch_run_id',
        'billing_year_month',
        'due_year_month',
        'status',
        'subtotal',
        'tax_total',
        'total',
        'paid_amount',
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
            'paid_amount' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice): void {
            if ($invoice->code !== null) {
                $invoice->code = IdentifierNormalizer::normalize($invoice->code);
            }
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function issuerBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'issuer_bp_id');
    }

    public function batchRun(): BelongsTo
    {
        return $this->belongsTo(BillingBatchRun::class, 'billing_batch_run_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function kickbacks(): HasMany
    {
        return $this->hasMany(KickbackInvoice::class, 'source_invoice_id');
    }

    /**
     * 入金金額（税込）と請求税込合計に差異があるか。
     */
    public function hasPaymentDifference(): bool
    {
        if ($this->paid_amount === null) {
            return false;
        }

        return (int) $this->paid_amount !== (int) $this->total;
    }

    /**
     * 入金按分率（未入金・請求税込0は 0）。
     */
    public function paymentRatio(): float
    {
        if ($this->status !== InvoiceStatus::Paid || $this->paid_amount === null) {
            return 0.0;
        }

        $total = (int) $this->total;
        if ($total <= 0) {
            return 0.0;
        }

        return max(0.0, (float) $this->paid_amount / (float) $total);
    }
}
