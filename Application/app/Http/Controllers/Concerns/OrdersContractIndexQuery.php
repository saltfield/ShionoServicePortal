<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Contract\Enums\ContractStatus;
use App\Models\ContractStatusHistory;
use Illuminate\Database\Eloquent\Builder;

trait OrdersContractIndexQuery
{
    protected function orderContractIndexQuery(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            ContractStatus::PendingPriceApproval->value => $query
                ->orderByRaw('applied_at is null')
                ->orderBy('applied_at')
                ->orderBy('id'),
            ContractStatus::Approved->value => $query
                ->orderBy(
                    ContractStatusHistory::query()
                        ->select('created_at')
                        ->whereColumn('contract_status_histories.contract_id', 'contracts.id')
                        ->where('to_status', ContractStatus::Approved->value)
                        ->orderByDesc('id')
                        ->limit(1)
                )
                ->orderBy('id'),
            ContractStatus::Activated->value => $query
                ->orderByRaw('activated_at is null')
                ->orderBy('activated_at')
                ->orderBy('id'),
            default => $query->latest('id'),
        };
    }
}
