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
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Support\TaxPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BillingService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly BpHierarchyService $hierarchy,
        private readonly NumberSequenceService $sequences,
    ) {}

    public function visibleQuery(User $actor): Builder
    {
        $query = Invoice::query()->with(['customer', 'owningBp', 'contract']);

        return match ($actor->user_type) {
            UserType::Admin => $query,
            UserType::Bp => $query->whereIn(
                'owning_bp_id',
                $this->hierarchy->descendantIdsIncludingSelf($actor->businessPartner)
            ),
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

        if ($contract->status !== ContractStatus::Activated) {
            throw new InvalidArgumentException('開通済の契約のみ請求を発行できます。');
        }

        if (! preg_match('/^\d{6}$/', $billingYearMonth)) {
            throw new InvalidArgumentException('請求月は YYYYMM 形式で指定してください。');
        }

        $contract->loadMissing(['items.item', 'customer', 'owningBp']);

        if (Invoice::query()
            ->where('contract_id', $contract->id)
            ->where('billing_year_month', $billingYearMonth)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists()) {
            throw new InvalidArgumentException('この契約・請求月の請求は既に発行されています。');
        }

        $activatedYm = optional($contract->activated_at)->timezone(config('app.timezone'))->format('Ym');
        $lines = [];
        $sort = 0;

        foreach ($contract->items as $line) {
            $type = $line->item?->billing_type;
            if ($type === BillingType::Initial && $activatedYm !== $billingYearMonth) {
                continue;
            }
            if ($type === BillingType::Running || ($type === BillingType::Initial && $activatedYm === $billingYearMonth)) {
                $unit = (int) $line->unit_price;
                $rate = (int) ($line->tax_rate ?? 10);
                $inclusive = TaxPrice::inclusive($unit, $rate);
                $tax = $inclusive - $unit;
                $lines[] = [
                    'contract_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'description' => trim(($line->item?->code ?? '').' / '.($line->item?->name ?? '品目')),
                    'billing_type' => $type->value,
                    'quantity' => 1,
                    'unit_price' => $unit,
                    'tax_rate' => $rate,
                    'amount' => $unit,
                    'tax_amount' => $tax,
                    'amount_inclusive' => $inclusive,
                    'sort_order' => $sort++,
                ];
            }
        }

        if ($lines === []) {
            throw new InvalidArgumentException('この請求月に計上できる品目がありません。');
        }

        $subtotal = array_sum(array_column($lines, 'amount'));
        $taxTotal = array_sum(array_column($lines, 'tax_amount'));
        $total = array_sum(array_column($lines, 'amount_inclusive'));

        return DB::transaction(function () use ($actor, $contract, $billingYearMonth, $note, $lines, $subtotal, $taxTotal, $total) {
            $invoice = Invoice::query()->create([
                'code' => $this->sequences->next(PartnerCodePrefix::Invoice),
                'contract_id' => $contract->id,
                'customer_id' => $contract->customer_id,
                'owning_bp_id' => $contract->owning_bp_id,
                'billing_year_month' => $billingYearMonth,
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
                ],
            );

            return $invoice->load('lines');
        });
    }

    public function markPaid(User $actor, Invoice $invoice): Invoice
    {
        $this->assertCanManageInvoice($actor, $invoice);

        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvalidArgumentException('発行済の請求のみ入金済にできます。');
        }

        $invoice->status = InvoiceStatus::Paid;
        $invoice->paid_at = now();
        $invoice->save();

        $this->auditLogger->log(
            'billing',
            'invoice.paid',
            'success',
            $actor,
            targetType: Invoice::class,
            targetId: $invoice->id,
            meta: ['code' => $invoice->code],
        );

        return $invoice->fresh();
    }

    public function cancel(User $actor, Invoice $invoice): Invoice
    {
        $this->assertCanManageInvoice($actor, $invoice);

        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw new InvalidArgumentException('既に取消済みです。');
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            throw new InvalidArgumentException('入金済の請求は取消できません。');
        }

        $invoice->status = InvoiceStatus::Cancelled;
        $invoice->cancelled_at = now();
        $invoice->save();

        $this->auditLogger->log(
            'billing',
            'invoice.cancel',
            'success',
            $actor,
            targetType: Invoice::class,
            targetId: $invoice->id,
            meta: ['code' => $invoice->code],
        );

        return $invoice->fresh();
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
