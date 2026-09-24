<?php

namespace App\Models;

use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ApplicationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    protected $fillable = [
        'type',
        'contract_id',
        'from_bp_id',
        'to_bp_id',
        'status',
        'payload_json',
        'amount',
        'decided_by_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApplicationType::class,
            'status' => ApplicationStatus::class,
            'payload_json' => 'array',
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function fromBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'from_bp_id');
    }

    public function toBp(): BelongsTo
    {
        return $this->belongsTo(BusinessPartner::class, 'to_bp_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function requiresApprovalChain(): bool
    {
        $chain = is_array($this->payload_json) ? ($this->payload_json['chain'] ?? null) : null;

        return is_array($chain) && ! empty($chain['required']);
    }

    /**
     * @return array{step: int, total: int}|null
     */
    public function approvalProgress(): ?array
    {
        if (! $this->requiresApprovalChain()) {
            return null;
        }

        $chain = $this->payload_json['chain'];
        $step = max(1, (int) ($chain['step'] ?? 1));
        $total = max(1, (int) ($chain['total'] ?? 1));

        return [
            'step' => min($step, $total),
            'total' => $total,
        ];
    }

    public function approvalProgressLabel(): ?string
    {
        $progress = $this->approvalProgress();

        return $progress === null ? null : $progress['step'].'/'.$progress['total'];
    }
}
