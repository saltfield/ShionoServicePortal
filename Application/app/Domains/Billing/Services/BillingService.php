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
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\TaxPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BillingService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
        private readonly NumberSequenceService $sequences,
        private readonly KickbackService $kickbacks,
    ) {}

    public function visibleQuery(User $actor): Builder
    {
        $query = Invoice::query()->with(['customer', 'owningBp', 'issuerBp', 'contract']);

        return match ($actor->user_type) {
            UserType::Admin => $query,
            UserType::Bp => $query->where(function ($q) use ($actor) {
                $ids = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
                $q->whereIn('owning_bp_id', $ids)->orWhereIn('issuer_bp_id', $ids);
            }),
            UserType::Customer => $query->where('customer_id', $actor->customer_id),
        };
    }

    public function assertVisible(User $actor, Invoice $invoice): void
    {
        $exists = $this->visibleQuery($actor)->whereKey($invoice->id)->exists();
        abort_unless($exists, 403, 'この請求を参照する権限がありません。');
    }

    public function issueFromContract(User $actor, Contract $contract, string $billingYearMonth, ?string $note = null): Invoice
    {
        $this->authorization->authorize($actor, 'invoice.manage', [
            'resource_type' => 'contract',
            'owner_bp_id' => $contract->owning_bp_id,
        ]);
        $this->assertCanManageContract($actor, $contract);

        return $this->generateForContract($contract, $billingYearMonth, $actor, $note);
    }

    /**
     * バッチ／システム生成（認可チェックなし）。
     */
    public function generateForContract(Contract $contract, string $billingYearMonth, ?User $actor = null, ?string $note = null, ?int $billingBatchRunId = null): ?Invoice
    {
        if ($contract->status !== ContractStatus::Activated) {
            throw new InvalidArgumentException('サービス提供開始の契約のみ請求を発行できます。');
        }

        if (! preg_match('/^\d{6}$/', $billingYearMonth)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        if (! $contract->auto_invoice_enabled) {
            return null;
        }

        if ($contract->billing_suspended) {
            return null;
        }

        if ($contract->end_user_billing_disabled) {
            return null;
        }

        $first = $contract->first_billing_year_month;
        $final = $contract->final_billing_year_month;
        if ($first && $billingYearMonth < $first) {
            return null;
        }
        if ($final && $billingYearMonth > $final) {
            return null;
        }

        if (Invoice::query()
            ->where('contract_id', $contract->id)
            ->where('billing_year_month', $billingYearMonth)
            ->where('status', '!=', InvoiceStatus::Withdrawn->value)
            ->exists()) {
            throw new InvalidArgumentException('この契約・請求月の請求は既に発行されています。');
        }

        $contract->loadMissing(['items.item', 'customer', 'owningBp']);
        $issuer = $this->hierarchy->rootOf($contract->owningBp);
        $lines = $this->buildCustomerLines($contract, $billingYearMonth);

        if ($lines === []) {
            return null;
        }

        $subtotal = array_sum(array_column($lines, 'amount'));
        $taxTotal = array_sum(array_column($lines, 'tax_amount'));
        $total = array_sum(array_column($lines, 'amount_inclusive'));
        $due = Carbon::createFromFormat('Ym', $billingYearMonth)->addMonthNoOverflow()->format('Ym');

        return DB::transaction(function () use ($actor, $contract, $issuer, $billingYearMonth, $due, $note, $lines, $subtotal, $taxTotal, $total, $billingBatchRunId) {
            $invoice = Invoice::query()->create([
                'code' => $this->sequences->next(PartnerCodePrefix::Invoice),
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'owning_bp_id' => $contract->owning_bp_id,
                'issuer_bp_id' => $issuer->id,
                'source' => 'auto',
                'billing_batch_run_id' => $billingBatchRunId,
                'billing_year_month' => $billingYearMonth,
                'due_year_month' => $due,
                'status' => InvoiceStatus::Issued,
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'total' => $total,
                'issued_at' => now(),
                'note' => $note,
            ]);

            foreach ($lines as $row) {
                InvoiceLine::query()->create(['invoice_id' => $invoice->id, ...$row]);
            }

            if ($actor) {
                $this->auditLogger->log(
                    'billing',
                    'invoice.issue',
                    'success',
                    $actor,
                    targetType: Invoice::class,
                    targetId: $invoice->id,
                    meta: [
                        'code' => $invoice->code,
                        'contract_id' => $contract->id,
                        'billing_year_month' => $billingYearMonth,
                        'total' => $total,
                        'billing_batch_run_id' => $billingBatchRunId,
                    ],
                );
            }

            return $invoice->load('lines');
        });
    }

    public function markPaid(User $actor, Invoice $invoice, ?int $paidAmount = null): Invoice
    {
        $this->assertCanManageInvoice($actor, $invoice);

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvalidArgumentException('発行済の請求のみ入金済にできます。');
        }

        $amount = $paidAmount ?? (int) $invoice->total;
        if ($amount < 0) {
            throw new InvalidArgumentException('入金金額は0以上で指定してください。');
        }

        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_amount = $amount;
        $invoice->paid_at = now();
        $invoice->save();

        $this->kickbacks->syncForSourceInvoice($invoice->fresh(), $actor, force: true);

        $this->auditLogger->log(
            'billing',
            'invoice.paid',
            'success',
            $actor,
            targetType: Invoice::class,
            targetId: $invoice->id,
            meta: ['code' => $invoice->code, 'paid_amount' => $amount],
        );

        return $invoice->fresh();
    }

    /**
     * 入金済請求の入金金額を管理者／権限者が修正し、キックバックを再計算する。
     */
    public function updatePaidAmount(User $actor, Invoice $invoice, int $paidAmount): Invoice
    {
        $this->assertCanManageInvoice($actor, $invoice);

        if ($invoice->status !== InvoiceStatus::Paid) {
            throw new InvalidArgumentException('入金済の請求のみ入金金額を修正できます。');
        }

        if ($paidAmount < 0) {
            throw new InvalidArgumentException('入金金額は0以上で指定してください。');
        }

        // 管理者のみ入金金額の事後修正を許可
        if ($actor->user_type !== UserType::Admin) {
            throw new InvalidArgumentException('入金金額の修正は管理者のみ可能です。');
        }

        $invoice->paid_amount = $paidAmount;
        $invoice->save();

        $this->kickbacks->syncForSourceInvoice($invoice->fresh(), $actor, force: true);

        $this->auditLogger->log(
            'billing',
            'invoice.paid_amount.update',
            'success',
            $actor,
            targetType: Invoice::class,
            targetId: $invoice->id,
            meta: ['code' => $invoice->code, 'paid_amount' => $paidAmount],
        );

        return $invoice->fresh();
    }

    public function withdraw(User $actor, Invoice $invoice): Invoice
    {
        $this->assertCanManageInvoice($actor, $invoice);

        if ($invoice->status === InvoiceStatus::Withdrawn) {
            throw new InvalidArgumentException('既に取下げ済みです。');
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            throw new InvalidArgumentException('入金済の請求は取下げできません。');
        }

        $this->kickbacks->withdrawForSourceInvoice($invoice, $actor);

        $invoice->status = InvoiceStatus::Withdrawn;
        $invoice->withdrawn_at = now();
        $invoice->save();

        $this->auditLogger->log(
            'billing',
            'invoice.withdraw',
            'success',
            $actor,
            targetType: Invoice::class,
            targetId: $invoice->id,
            meta: ['code' => $invoice->code],
        );

        return $invoice->fresh();
    }

    /** @deprecated use withdraw() */
    public function cancel(User $actor, Invoice $invoice): Invoice
    {
        return $this->withdraw($actor, $invoice);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildCustomerLines(Contract $contract, string $billingYearMonth): array
    {
        $firstYm = $contract->first_billing_year_month
            ?: optional($contract->activated_at)->timezone(config('app.timezone'))->format('Ym');
        $lines = [];
        $sort = 0;

        foreach ($contract->items as $line) {
            if (! $this->shouldIncludeCustomerLine($line, $billingYearMonth, $firstYm)) {
                continue;
            }

            $type = $line->item?->billing_type;
            $unit = (int) $line->unit_price;
            $rate = (int) ($line->tax_rate ?? 10);
            $inclusive = TaxPrice::inclusive($unit, $rate);
            $tax = $inclusive - $unit;
            $lines[] = [
                'contract_item_id' => $line->id,
                'item_id' => $line->item_id,
                'description' => trim(($line->item?->code ?? '').' / '.($line->item?->name ?? '品目')),
                'billing_type' => $type?->value ?? 'running',
                'quantity' => 1,
                'unit_price' => $unit,
                'tax_rate' => $rate,
                'amount' => $unit,
                'tax_amount' => $tax,
                'amount_inclusive' => $inclusive,
                'sort_order' => $sort++,
            ];
        }

        return $lines;
    }

    private function shouldIncludeCustomerLine(ContractItem $line, string $billingYearMonth, ?string $firstYm): bool
    {
        $line->loadMissing(['item', 'contract']);

        if ($line->effectiveEndUserBillingDisabled()) {
            return false;
        }

        $type = $line->item?->billing_type;
        if ($type === BillingType::Initial) {
            if (! $line->effectiveBillInitialInSystem()) {
                return false;
            }

            if ($firstYm === null) {
                return false;
            }

            if ($billingYearMonth === $firstYm) {
                return true;
            }

            // 開始月のバッチを逃した場合、未請求のイニシャルのみ後続月でキャッチアップ
            if ($billingYearMonth > $firstYm) {
                return ! $this->hasIssuedInitialLine($line);
            }

            return false;
        }

        if ($type === BillingType::Running) {
            return true;
        }

        return false;
    }

    private function hasIssuedInitialLine(ContractItem $line): bool
    {
        return InvoiceLine::query()
            ->where('contract_item_id', $line->id)
            ->where('billing_type', BillingType::Initial->value)
            ->whereHas('invoice', function (Builder $query): void {
                $query->where('status', '!=', InvoiceStatus::Withdrawn->value);
            })
            ->exists();
    }

    private function assertCanManageInvoice(User $actor, Invoice $invoice): void
    {
        $this->authorization->authorize($actor, 'invoice.manage', [
            'resource_type' => 'contract',
            'owner_bp_id' => $invoice->owning_bp_id,
        ]);
        $this->assertVisible($actor, $invoice);

        if ($actor->user_type === UserType::Customer) {
            throw new InvalidArgumentException('カスタマーは請求ステータスを変更できません。');
        }
    }

    private function assertCanManageContract(User $actor, Contract $contract): void
    {
        if ($actor->user_type === UserType::Admin) {
            return;
        }

        if ($actor->user_type !== UserType::Bp || $actor->businessPartner === null) {
            throw new InvalidArgumentException('請求発行は管理者またはBPのみ可能です。');
        }

        $scope = $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner);
        if (! in_array((int) $contract->owning_bp_id, $scope, true)) {
            throw new InvalidArgumentException('配下外の契約には請求を発行できません。');
        }
    }
}
