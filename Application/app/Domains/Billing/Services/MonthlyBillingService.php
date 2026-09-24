<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Enums\BillingBatchRunStatus;
use App\Domains\Billing\Enums\BillingBatchRunTrigger;
use App\Domains\Contract\Enums\ContractStatus;
use App\Models\BillingBatchError;
use App\Models\BillingBatchRun;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

class MonthlyBillingService
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly KickbackService $kickbacks,
        private readonly BillingScheduleService $schedule,
    ) {}

    /**
     * @return array{billing_year_month: string, invoices: int, kickbacks: int, skipped: int, errors: int, run_id: int, status: string}
     */
    public function run(?string $billingYearMonth = null, ?User $actor = null, ?BillingBatchRunTrigger $trigger = null): array
    {
        $ym = $billingYearMonth ?: $this->schedule->billingYearMonthFor(now());
        if (! preg_match('/^\d{6}$/', $ym)) {
            throw new \InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        $trigger ??= $actor ? BillingBatchRunTrigger::Manual : BillingBatchRunTrigger::Scheduled;

        $run = BillingBatchRun::query()->create([
            'billing_year_month' => $ym,
            'trigger' => $trigger,
            'status' => BillingBatchRunStatus::Success,
            'actor_user_id' => $actor?->id,
            'started_at' => now(),
        ]);

        $stats = [
            'billing_year_month' => $ym,
            'invoices' => 0,
            'kickbacks' => 0,
            'skipped' => 0,
            'errors' => 0,
            'run_id' => $run->id,
            'status' => BillingBatchRunStatus::Success->value,
        ];

        $contracts = Contract::query()
            ->with(['owningBp', 'items.item', 'items.priceLayers', 'items.contract'])
            ->where('status', ContractStatus::Activated->value)
            ->where('auto_invoice_enabled', true)
            ->where('billing_suspended', false)
            ->get();

        foreach ($contracts as $contract) {
            try {
                $invoice = $this->billing->generateForContract($contract, $ym, $actor, null, $run->id);
                if ($invoice) {
                    $stats['invoices']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->recordError($run, $contract, $ym, 'customer_invoice', $e);
            }

            try {
                $created = $this->kickbacks->generateForContract($contract, $ym, $actor, $run->id);
                $stats['kickbacks'] += count($created);
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->recordError($run, $contract, $ym, 'kickback', $e);
            }
        }

        $status = $this->resolveStatus($stats);
        $run->update([
            'status' => $status,
            'invoices_count' => $stats['invoices'],
            'kickbacks_count' => $stats['kickbacks'],
            'skipped_count' => $stats['skipped'],
            'errors_count' => $stats['errors'],
            'finished_at' => now(),
        ]);
        $stats['status'] = $status->value;

        return $stats;
    }

    public function runIfScheduled(?Carbon $now = null): ?array
    {
        $now ??= now();
        if (! $this->schedule->shouldRunAt($now)) {
            return null;
        }

        $ym = $this->schedule->billingYearMonthFor($now);

        // 同一請求月の自動実行は1回のみ（設定時刻以降の取りこぼし回収用）
        $alreadyRan = BillingBatchRun::query()
            ->where('billing_year_month', $ym)
            ->where('trigger', BillingBatchRunTrigger::Scheduled)
            ->exists();

        if ($alreadyRan) {
            return null;
        }

        return $this->run(
            $ym,
            trigger: BillingBatchRunTrigger::Scheduled,
        );
    }

    /**
     * @param  array{invoices: int, kickbacks: int, skipped: int, errors: int}  $stats
     */
    private function resolveStatus(array $stats): BillingBatchRunStatus
    {
        if ($stats['errors'] === 0) {
            return BillingBatchRunStatus::Success;
        }

        if ($stats['invoices'] > 0 || $stats['kickbacks'] > 0 || $stats['skipped'] > 0) {
            return BillingBatchRunStatus::Partial;
        }

        return BillingBatchRunStatus::Failed;
    }

    private function recordError(
        BillingBatchRun $run,
        Contract $contract,
        string $ym,
        string $phase,
        Throwable $e,
    ): void {
        BillingBatchError::query()->create([
            'billing_batch_run_id' => $run->id,
            'contract_id' => $contract->id,
            'billing_year_month' => $ym,
            'phase' => $phase,
            'message' => $e->getMessage(),
            'context' => [
                'exception' => class_basename($e),
            ],
        ]);
    }
}
