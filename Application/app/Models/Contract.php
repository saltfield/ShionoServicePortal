<?php

namespace App\Models;

use App\Domains\Auth\Support\IdentifierNormalizer;
use App\Domains\Contract\Enums\ContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'site_id',
        'customer_id',
        'owning_bp_id',
        'status',
        'special_price_requested',
        'special_price_reason',
        'applied_at',
        'activated_at',
        'first_billing_year_month',
        'final_billing_year_month',
        'cancelled_at',
        'cancellation_amount',
        'cancellation_note',
        'minimum_term_months_snapshot',
        'auto_invoice_enabled',
        'billing_suspended',
        'end_user_billing_disabled',
        'bill_initial_in_system',
        'kickback_start_year_month',
        'recalc_on_price_change',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'special_price_requested' => 'boolean',
            'applied_at' => 'datetime',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_amount' => 'integer',
            'minimum_term_months_snapshot' => 'integer',
            'auto_invoice_enabled' => 'boolean',
            'billing_suspended' => 'boolean',
            'end_user_billing_disabled' => 'boolean',
            'bill_initial_in_system' => 'boolean',
            'recalc_on_price_change' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contract $contract): void {
            if ($contract->code !== null) {
                $contract->code = IdentifierNormalizer::normalize($contract->code);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owningBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'owning_bp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    public function dataRows(): HasMany
    {
        return $this->hasMany(ContractData::class)->orderBy('sort_order');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ContractStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ContractMessage::class)->orderBy('created_at');
    }

    public function messageReads(): HasMany
    {
        return $this->hasMany(ContractMessageRead::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function kickbackInvoices(): HasMany
    {
        return $this->hasMany(KickbackInvoice::class);
    }

    public function effectiveKickbackStartYearMonth(): ?string
    {
        if ($this->kickback_start_year_month) {
            return $this->kickback_start_year_month;
        }

        $first = $this->first_billing_year_month;
        if (! $first || preg_match('/^\d{6}$/', $first) !== 1) {
            return null;
        }

        return \Illuminate\Support\Carbon::createFromFormat('Ym', $first)
            ->addMonthsNoOverflow(6)
            ->format('Ym');
    }
}
