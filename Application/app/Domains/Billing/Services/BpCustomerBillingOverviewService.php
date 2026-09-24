<?php

namespace App\Domains\Billing\Services;

use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Contract\Enums\ContractStatus;
use App\Models\BusinessPartner;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\KickbackInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BpCustomerBillingOverviewService
{
    public function __construct(
        private readonly BpHierarchyService $hierarchy,
        private readonly KickbackService $kickbacks,
    ) {}

    /**
     * 指定BP配下（子孫BPが管理するカスタマー含む）の
     * ルート→カスタマー請求とキックバック（発行済＋計算プレビュー）を返す。
     *
     * @return array{
     *   subtree_bp_ids: list<int>,
     *   invoices: LengthAwarePaginator,
     *   invoice_totals: array{subtotal: int, tax_total: int, total: int, count: int},
     *   kickback_invoices: LengthAwarePaginator,
     *   kickback_preview: list<array<string, mixed>>,
     *   kickback_preview_totals: array{subtotal: int, tax_total: int, total: int}
     * }
     */
    public function forPartner(BusinessPartner $partner): array
    {
        $subtreeBpIds = $this->hierarchy->descendantIdsIncludingSelf($partner);

        $invoiceBase = Invoice::query()
            ->with(['customer', 'contract', 'issuerBp', 'owningBp'])
            ->whereIn('owning_bp_id', $subtreeBpIds)
            ->where('status', '!=', InvoiceStatus::Withdrawn->value);

        $invoiceTotals = [
            'subtotal' => (int) (clone $invoiceBase)->sum('subtotal'),
            'tax_total' => (int) (clone $invoiceBase)->sum('tax_total'),
            'total' => (int) (clone $invoiceBase)->sum('total'),
            'count' => (int) (clone $invoiceBase)->count(),
        ];

        $invoices = (clone $invoiceBase)
            ->latest('id')
            ->paginate(20, ['*'], 'invoices_page')
            ->withQueryString();

        $contractIds = Contract::query()
            ->whereIn('owning_bp_id', $subtreeBpIds)
            ->pluck('id');

        $kickbackInvoices = KickbackInvoice::query()
            ->with(['contract', 'fromBp', 'toBp'])
            ->whereIn('contract_id', $contractIds)
            ->where('status', '!=', InvoiceStatus::Withdrawn->value)
            ->latest('id')
            ->paginate(20, ['*'], 'kickbacks_page')
            ->withQueryString();

        $contracts = Contract::query()
            ->with(['customer', 'owningBp', 'items.item', 'items.priceLayers', 'items.contract'])
            ->whereIn('owning_bp_id', $subtreeBpIds)
            ->where('status', ContractStatus::Activated->value)
            ->where('end_user_billing_disabled', false)
            ->orderBy('code')
            ->get();

        $preview = [];
        $previewSubtotal = 0;
        $previewTax = 0;
        $previewTotal = 0;

        foreach ($contracts as $contract) {
            $edges = $this->kickbacks->previewEdgesForContract($contract);
            foreach ($edges as $edge) {
                $previewSubtotal += $edge['subtotal'];
                $previewTax += $edge['tax_total'];
                $previewTotal += $edge['total'];
                $preview[] = [
                    'contract' => $contract,
                    'customer' => $contract->customer,
                    'owning_bp' => $contract->owningBp,
                    'from_bp_id' => $edge['from_bp_id'],
                    'to_bp_id' => $edge['to_bp_id'],
                    'from_bp' => $edge['from_bp'],
                    'to_bp' => $edge['to_bp'],
                    'subtotal' => $edge['subtotal'],
                    'tax_total' => $edge['tax_total'],
                    'total' => $edge['total'],
                    'involves_partner' => in_array((int) $partner->id, [$edge['from_bp_id'], $edge['to_bp_id']], true),
                    'error' => $edge['error'] ?? null,
                ];
            }
        }

        return [
            'subtree_bp_ids' => $subtreeBpIds,
            'invoices' => $invoices,
            'invoice_totals' => $invoiceTotals,
            'kickback_invoices' => $kickbackInvoices,
            'kickback_preview' => $preview,
            'kickback_preview_totals' => [
                'subtotal' => $previewSubtotal,
                'tax_total' => $previewTax,
                'total' => $previewTotal,
            ],
        ];
    }
}
