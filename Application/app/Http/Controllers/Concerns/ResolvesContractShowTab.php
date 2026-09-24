<?php

namespace App\Http\Controllers\Concerns;

use App\Domains\Contract\Services\ContractService;
use App\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait ResolvesContractShowTab
{
    protected function resolveReturnCustomerId(Request $request, Contract $contract): ?int
    {
        if (! $request->filled('return_customer_id')) {
            return null;
        }

        $id = (int) $request->input('return_customer_id');

        return (int) $contract->customer_id === $id ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function contractShowRouteParams(Request $request, Contract $contract, array $extra = []): array
    {
        $params = array_merge(['contract' => $contract], $extra);
        $returnCustomerId = $this->resolveReturnCustomerId($request, $contract);
        if ($returnCustomerId !== null) {
            $params['return_customer_id'] = $returnCustomerId;
        }

        return $params;
    }

    protected function redirectToContractShow(
        Request $request,
        string $prefix,
        Contract $contract,
        string $tab,
        string $status,
    ): RedirectResponse {
        return redirect()
            ->route("{$prefix}.contracts.show", $this->contractShowRouteParams($request, $contract, ['tab' => $tab]))
            ->with('status', $status);
    }

    protected function redirectAfterContractLeave(
        Request $request,
        string $prefix,
        Contract $contract,
        string $status,
    ): RedirectResponse {
        $returnCustomerId = $this->resolveReturnCustomerId($request, $contract);
        if ($returnCustomerId !== null) {
            return redirect()
                ->route("{$prefix}.customers.show", ['customer' => $returnCustomerId, 'tab' => 'contracts'])
                ->with('status', $status);
        }

        return redirect()
            ->route("{$prefix}.contracts.index")
            ->with('status', $status);
    }

    /**
     * @return list<string>
     */
    protected function contractShowTabs(bool $isCustomerPortal): array
    {
        $tabs = ['overview', 'items', 'billing', 'data', 'messages', 'history'];
        if ($isCustomerPortal) {
            return ['overview', 'items', 'data', 'messages', 'history'];
        }

        return $tabs;
    }

    protected function resolveContractShowTab(Request $request, bool $isCustomerPortal = false): string
    {
        $tab = (string) $request->input('tab', 'overview');
        $allowed = $this->contractShowTabs($isCustomerPortal);

        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    /**
     * @return array{suggested_amount: int, remaining_months: int, minimum_term_months: int, elapsed_months: int}|null
     */
    protected function defaultCancellationSuggestion(Contract $contract, ContractService $service): ?array
    {
        if ($contract->status->value !== 'activated' || ! $contract->first_billing_year_month) {
            return null;
        }

        try {
            return $service->suggestCancellationAmount(
                $contract,
                now()->timezone(config('app.timezone'))->format('Ym')
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    protected function resolveFirstBillingYearMonth(Request $request): string
    {
        $validated = $request->validate([
            'first_billing_mode' => ['required', 'in:this_month,next_month,custom'],
            'first_billing_year_month' => ['nullable', 'string'],
        ]);

        $now = now()->timezone(config('app.timezone'));

        return match ($validated['first_billing_mode']) {
            'this_month' => $now->format('Ym'),
            'next_month' => $now->copy()->addMonthNoOverflow()->format('Ym'),
            default => (string) ($validated['first_billing_year_month'] ?? ''),
        };
    }
}
