<?php

namespace App\Domains\Billing\Services;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Iam\Services\AuditLogger;
use App\Domains\Iam\Services\AuthorizationService;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\KickbackInvoice;
use App\Models\KickbackInvoiceLine;
use App\Models\User;
use App\Support\TaxPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class KickbackService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
        private readonly NumberSequenceService $sequences,
    ) {}

    public function visibleQuery(User $actor): Builder
    {
        $query = KickbackInvoice::query()->with(['contract', 'fromBp', 'toBp', 'lines']);

        return match ($actor->user_type) {
            UserType::Admin => $query,
            UserType::Bp => $query->where(function ($q) use ($actor) {
                $bpId = (int) $actor->bp_id;
                $q->where('from_bp_id', $bpId)->orWhere('to_bp_id', $bpId);
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function assertVisible(User $actor, KickbackInvoice $invoice): void
    {
        $visible = $this->visibleQuery($actor)->whereKey($invoice->id)->exists();
        abort_unless($visible, 403);
    }

    /**
     * 契約の価格レイヤからキックバック区間を計算（発行はしない）。
     * ランニング明細を月次想定として集計。イニシャルは含めない。
     *
     * @return list<array{
     *   from_bp_id: int,
     *   to_bp_id: int,
     *   from_bp: ?BusinessPartner,
     *   to_bp: ?BusinessPartner,
     *   subtotal: int,
     *   tax_total: int,
     *   total: int,
     *   lines: list<array<string, mixed>>,
     *   error: ?string
     * }>
     */
    public function previewEdgesForContract(Contract $contract): array
    {
        if ($contract->status !== ContractStatus::Activated) {
            return [];
        }

        if ($contract->end_user_billing_disabled) {
            return [];
        }

        $contract->loadMissing(['owningBp', 'items.item', 'items.priceLayers', 'items.contract']);

        $eligibleItems = $contract->items->filter(function (ContractItem $line) {
            if ($line->effectiveEndUserBillingDisabled()) {
                return false;
            }

            return $line->item?->billing_type === BillingType::Running;
        });

        if ($eligibleItems->isEmpty()) {
            return [];
        }

        try {
            $edgesByPair = $this->buildEdgesByPair($eligibleItems);
        } catch (InvalidArgumentException $exception) {
            return [[
                'from_bp_id' => 0,
                'to_bp_id' => 0,
                'from_bp' => null,
                'to_bp' => null,
                'subtotal' => 0,
                'tax_total' => 0,
                'total' => 0,
                'lines' => [],
                'error' => $exception->getMessage(),
            ]];
        }

        $bpIds = collect($edgesByPair)
            ->flatMap(fn (array $edge) => [$edge['from_bp_id'], $edge['to_bp_id']])
            ->unique()
            ->values()
            ->all();
        $bps = BusinessPartner::query()->whereIn('id', $bpIds)->get()->keyBy('id');

        $result = [];
        foreach ($edgesByPair as $edge) {
            if ($edge['lines'] === []) {
                continue;
            }
            $subtotal = array_sum(array_column($edge['lines'], 'amount'));
            $taxTotal = array_sum(array_column($edge['lines'], 'tax_amount'));
            $total = array_sum(array_column($edge['lines'], 'amount_inclusive'));
            $result[] = [
                'from_bp_id' => $edge['from_bp_id'],
                'to_bp_id' => $edge['to_bp_id'],
                'from_bp' => $bps->get($edge['from_bp_id']),
                'to_bp' => $bps->get($edge['to_bp_id']),
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'lines' => $edge['lines'],
                'error' => null,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, ContractItem>  $eligibleItems
     * @return array<string, array{from_bp_id: int, to_bp_id: int, lines: list<array<string, mixed>>}>
     */
    private function buildEdgesByPair($eligibleItems): array
    {
        $edgesByPair = [];
        foreach ($eligibleItems as $line) {
            $layers = $line->priceLayers->sortBy('depth_from_root')->values();
            if ($layers->isEmpty()) {
                throw new InvalidArgumentException("契約明細 #{$line->id} に価格レイヤがありません。価格承認を確認してください。");
            }

            $ordered = $layers->sortByDesc('depth_from_root')->values();
            $upper = (int) $line->unit_price;

            foreach ($ordered as $layer) {
                $partition = (int) $layer->amount;
                $amount = $upper - $partition;
                $key = $layer->buyer_bp_id.'|'.$layer->seller_bp_id;
                if (! isset($edgesByPair[$key])) {
                    $edgesByPair[$key] = [
                        'from_bp_id' => (int) $layer->buyer_bp_id,
                        'to_bp_id' => (int) $layer->seller_bp_id,
                        'lines' => [],
                    ];
                }

                if ($amount < 0) {
                    throw new InvalidArgumentException(sprintf(
                        'キックバック差額が負です（明細#%d 区間 %d→%d）。差額=%d（絶対値=%d）。上値=%d 仕切り=%d',
                        $line->id,
                        $layer->buyer_bp_id,
                        $layer->seller_bp_id,
                        $amount,
                        abs($amount),
                        $upper,
                        $partition,
                    ));
                }

                $rate = (int) ($line->tax_rate ?? 10);
                $inclusive = TaxPrice::inclusive($amount, $rate);
                $edgesByPair[$key]['lines'][] = [
                    'contract_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'seller_bp_id' => $layer->seller_bp_id,
                    'buyer_bp_id' => $layer->buyer_bp_id,
                    'description' => trim(($line->item?->code ?? '').' / '.($line->item?->name ?? '品目')),
                    'upper_amount' => $upper,
                    'partition_amount' => $partition,
                    'amount' => $amount,
                    'tax_rate' => $rate,
                    'tax_amount' => $inclusive - $amount,
                    'amount_inclusive' => $inclusive,
                ];

                $upper = $partition;
            }
        }

        return $edgesByPair;
    }

    /**
     * @return list<KickbackInvoice>
     */
    public function generateForContract(Contract $contract, string $billingYearMonth, ?User $actor = null, ?int $billingBatchRunId = null): array
    {
        if ($contract->status !== ContractStatus::Activated) {
            return [];
        }

        if ($contract->end_user_billing_disabled) {
            return [];
        }

        if (! preg_match('/^\d{6}$/', $billingYearMonth)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        $start = $contract->effectiveKickbackStartYearMonth();
        if ($start === null || $billingYearMonth < $start) {
            return [];
        }

        $contract->loadMissing(['owningBp', 'items.item', 'items.priceLayers', 'items.contract']);
        $eligibleItems = $contract->items->filter(fn (ContractItem $line) => $this->shouldIncludeKickbackLine($line, $billingYearMonth, $contract));
        if ($eligibleItems->isEmpty()) {
            return [];
        }

        $edgesByPair = $this->buildEdgesByPair($eligibleItems);

        $created = [];
        $due = Carbon::createFromFormat('Ym', $billingYearMonth)->addMonthNoOverflow()->format('Ym');

        return DB::transaction(function () use ($edgesByPair, $contract, $billingYearMonth, $due, $actor, $billingBatchRunId, &$created) {
            foreach ($edgesByPair as $edge) {
                if ($edge['lines'] === []) {
                    continue;
                }

                if (KickbackInvoice::query()
                    ->where('contract_id', $contract->id)
                    ->where('from_bp_id', $edge['from_bp_id'])
                    ->where('to_bp_id', $edge['to_bp_id'])
                    ->where('billing_year_month', $billingYearMonth)
                    ->where('status', '!=', InvoiceStatus::Withdrawn->value)
                    ->exists()) {
                    continue;
                }

                $subtotal = array_sum(array_column($edge['lines'], 'amount'));
                $taxTotal = array_sum(array_column($edge['lines'], 'tax_amount'));
                $total = array_sum(array_column($edge['lines'], 'amount_inclusive'));

                $invoice = KickbackInvoice::query()->create([
                    'code' => $this->sequences->next(PartnerCodePrefix::Kickback),
                    'contract_id' => $contract->id,
                    'billing_batch_run_id' => $billingBatchRunId,
                    'from_bp_id' => $edge['from_bp_id'],
                    'to_bp_id' => $edge['to_bp_id'],
                    'billing_year_month' => $billingYearMonth,
                    'due_year_month' => $due,
                    'status' => InvoiceStatus::Issued,
                    'subtotal' => $subtotal,
                    'tax_total' => $taxTotal,
                    'total' => $total,
                    'issued_at' => now(),
                ]);

                $sort = 0;
                foreach ($edge['lines'] as $row) {
                    KickbackInvoiceLine::query()->create([
                        'kickback_invoice_id' => $invoice->id,
                        ...$row,
                        'sort_order' => $sort++,
                    ]);
                }

                if ($actor) {
                    $this->auditLogger->log(
                        'billing',
                        'kickback.issue',
                        'success',
                        $actor,
                        targetType: KickbackInvoice::class,
                        targetId: $invoice->id,
                        meta: [
                            'code' => $invoice->code,
                            'contract_id' => $contract->id,
                            'billing_year_month' => $billingYearMonth,
                            'from_bp_id' => $edge['from_bp_id'],
                            'to_bp_id' => $edge['to_bp_id'],
                            'billing_batch_run_id' => $billingBatchRunId,
                        ],
                    );
                }

                $created[] = $invoice->load('lines');
            }

            return $created;
        });
    }

    public function markPaid(User $actor, KickbackInvoice $invoice): KickbackInvoice
    {
        $this->assertCanManage($actor, $invoice);
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvalidArgumentException('発行済のキックバックのみ入金済にできます。');
        }
        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_at = now();
        $invoice->save();

        return $invoice->fresh();
    }

    public function withdraw(User $actor, KickbackInvoice $invoice): KickbackInvoice
    {
        $this->assertCanManage($actor, $invoice);
        if ($invoice->status === InvoiceStatus::Withdrawn) {
            throw new InvalidArgumentException('既に取下げ済みです。');
        }
        if ($invoice->status === InvoiceStatus::Paid) {
            throw new InvalidArgumentException('入金済のキックバックは取下げできません。');
        }
        $invoice->status = InvoiceStatus::Withdrawn;
        $invoice->withdrawn_at = now();
        $invoice->save();

        return $invoice->fresh();
    }

    private function shouldIncludeKickbackLine(ContractItem $line, string $billingYearMonth, Contract $contract): bool
    {
        if ($line->effectiveEndUserBillingDisabled()) {
            return false;
        }

        $type = $line->item?->billing_type;
        $firstYm = $contract->first_billing_year_month
            ?: optional($contract->activated_at)->timezone(config('app.timezone'))->format('Ym');

        if ($type === BillingType::Initial) {
            if (! $line->effectiveBillInitialInSystem()) {
                return false;
            }

            return $firstYm !== null && $firstYm === $billingYearMonth;
        }

        return $type === BillingType::Running;
    }

    private function assertCanManage(User $actor, KickbackInvoice $invoice): void
    {
        $this->authorization->authorize($actor, 'invoice.manage', [
            'resource_type' => 'contract',
            'owner_bp_id' => $invoice->contract?->owning_bp_id,
        ]);

        if ($actor->user_type === UserType::Admin) {
            return;
        }

        if ($actor->user_type !== UserType::Bp) {
            throw new InvalidArgumentException('キックバックを操作する権限がありません。');
        }

        $bpId = (int) $actor->bp_id;
        if ($bpId !== (int) $invoice->from_bp_id && $bpId !== (int) $invoice->to_bp_id) {
            throw new InvalidArgumentException('自区間以外のキックバックは操作できません。');
        }
    }
}
