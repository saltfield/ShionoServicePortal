<?php

namespace App\Domains\Billing\Services;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Billing\Enums\BillingBatchRunStatus;
use App\Domains\Billing\Enums\BillingBatchRunTrigger;
use App\Domains\Contract\Enums\ContractStatus;
use App\Models\BillingBatchError;
use App\Models\BillingBatchRun;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class MonthlyBillingService
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly KickbackService $kickbacks,
        private readonly BillingScheduleService $schedule,
        private readonly BpHierarchyService $hierarchy,
    ) {}

    /**
     * @param  array{type: 'bp'|'customer', id: int}|null  $scope
     * @return array{billing_year_month: string, invoices: int, kickbacks: int, skipped: int, errors: int, run_id: int, status: string, scope: ?array}
     */
    public function run(
        ?string $billingYearMonth = null,
        ?User $actor = null,
        ?BillingBatchRunTrigger $trigger = null,
        ?array $scope = null,
    ): array {
        $ym = $billingYearMonth ?: $this->schedule->billingYearMonthFor(now());
        if (! preg_match('/^\d{6}$/', $ym)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        $normalizedScope = $scope === null ? null : $this->normalizeScope($scope);
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
            'scope' => $normalizedScope,
        ];

        $contracts = $this->eligibleContractsQuery($normalizedScope)
            ->with(['owningBp', 'items.item', 'items.priceLayers', 'items.contract'])
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
     * 過去月など、複数請求月を古い順に連続実行する（導入時バックフィル用）。
     * BP（配下含む）・BP単体・カスタマーのいずれかを必ず指定する。
     *
     * @param  array{type: 'bp_tree'|'bp'|'customer', id: int}  $scope
     * @return array{
     *     from: string,
     *     to: string,
     *     scope: array{type: string, id: int, code: string, name: string},
     *     months: list<array{billing_year_month: string, invoices: int, kickbacks: int, skipped: int, errors: int, run_id: int, status: string, scope: ?array}>,
     *     invoices: int,
     *     kickbacks: int,
     *     skipped: int,
     *     errors: int
     * }
     */
    public function runRange(string $fromYearMonth, string $toYearMonth, array $scope, ?User $actor = null): array
    {
        $normalizedScope = $this->normalizeScope($scope);
        $months = $this->yearMonthsBetween($fromYearMonth, $toYearMonth);
        $aggregated = [
            'from' => $fromYearMonth,
            'to' => $toYearMonth,
            'scope' => $normalizedScope,
            'months' => [],
            'invoices' => 0,
            'kickbacks' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($months as $ym) {
            $stats = $this->run($ym, $actor, BillingBatchRunTrigger::Manual, $normalizedScope);
            $aggregated['months'][] = $stats;
            $aggregated['invoices'] += $stats['invoices'];
            $aggregated['kickbacks'] += $stats['kickbacks'];
            $aggregated['skipped'] += $stats['skipped'];
            $aggregated['errors'] += $stats['errors'];
        }

        return $aggregated;
    }

    /**
     * @return list<string>
     */
    public function yearMonthsBetween(string $fromYearMonth, string $toYearMonth): array
    {
        if (! preg_match('/^\d{6}$/', $fromYearMonth) || ! preg_match('/^\d{6}$/', $toYearMonth)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        if ($fromYearMonth > $toYearMonth) {
            throw new InvalidArgumentException('開始月は終了月以前を指定してください。');
        }

        $cursor = Carbon::create(
            (int) substr($fromYearMonth, 0, 4),
            (int) substr($fromYearMonth, 4, 2),
            1,
            0,
            0,
            0,
            'Asia/Tokyo',
        )->startOfMonth();
        $end = Carbon::create(
            (int) substr($toYearMonth, 0, 4),
            (int) substr($toYearMonth, 4, 2),
            1,
            0,
            0,
            0,
            'Asia/Tokyo',
        )->startOfMonth();
        $months = [];

        // 安全上限（導入時バックフィル想定）
        for ($i = 0; $i < 120 && $cursor->lte($end); $i++) {
            $months[] = $cursor->format('Ym');
            $cursor->addMonth();
        }

        if ($cursor->lte($end)) {
            throw new InvalidArgumentException('請求月の範囲は最大120ヶ月までです。');
        }

        return $months;
    }

    /**
     * @param  array{type: string, id?: int, code?: string}  $scope
     * @return array{type: string, id: int, code: string, name: string}
     */
    public function normalizeScope(array $scope): array
    {
        $type = $scope['type'] ?? '';
        if (! in_array($type, ['bp_tree', 'bp', 'customer'], true)) {
            throw new InvalidArgumentException('対象は BP（配下含む）・BP単体・カスタマーのいずれかを指定してください。');
        }

        if ($type === 'bp_tree' || $type === 'bp') {
            $partner = null;
            if (! empty($scope['id'])) {
                $partner = BusinessPartner::query()->find((int) $scope['id']);
            } elseif (! empty($scope['code'])) {
                $partner = BusinessPartner::query()->where('code', $scope['code'])->first();
            }
            if ($partner === null) {
                throw new InvalidArgumentException('指定の BP が見つかりません。');
            }

            return [
                'type' => $type,
                'id' => (int) $partner->id,
                'code' => (string) $partner->code,
                'name' => (string) $partner->name,
            ];
        }

        $customer = null;
        if (! empty($scope['id'])) {
            $customer = Customer::query()->find((int) $scope['id']);
        } elseif (! empty($scope['code'])) {
            $customer = Customer::query()->where('code', $scope['code'])->first();
        }
        if ($customer === null) {
            throw new InvalidArgumentException('指定のカスタマーが見つかりません。');
        }

        return [
            'type' => 'customer',
            'id' => (int) $customer->id,
            'code' => (string) $customer->code,
            'name' => (string) $customer->name,
        ];
    }

    /**
     * @param  array{type: string, id: int, code: string, name: string}|null  $scope
     * @return Builder<Contract>
     */
    private function eligibleContractsQuery(?array $scope): Builder
    {
        $query = Contract::query()
            ->where('status', ContractStatus::Activated->value)
            ->where('auto_invoice_enabled', true)
            ->where('billing_suspended', false);

        if ($scope === null) {
            return $query;
        }

        if ($scope['type'] === 'customer') {
            return $query->where('customer_id', $scope['id']);
        }

        if ($scope['type'] === 'bp') {
            return $query->where(function (Builder $inner) use ($scope) {
                $inner->where('owning_bp_id', $scope['id'])
                    ->orWhereHas('customer', function (Builder $customer) use ($scope) {
                        $customer->where('managing_bp_id', $scope['id']);
                    });
            });
        }

        // bp_tree: 指定 BP ＋配下
        $partner = BusinessPartner::query()->findOrFail($scope['id']);
        $bpIds = $this->hierarchy->descendantIdsIncludingSelf($partner);

        return $query->where(function (Builder $inner) use ($bpIds) {
            $inner->whereIn('owning_bp_id', $bpIds)
                ->orWhereHas('customer', function (Builder $customer) use ($bpIds) {
                    $customer->whereIn('managing_bp_id', $bpIds);
                });
        });
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
