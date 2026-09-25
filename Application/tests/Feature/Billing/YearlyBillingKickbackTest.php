<?php

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Billing\Services\KickbackService;
use App\Domains\Billing\Services\MonthlyBillingService;
use App\Models\Invoice;
use App\Models\KickbackInvoice;
use Illuminate\Support\Carbon;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(Database\Seeders\IamSeeder::class);
    $this->seed(Database\Seeders\AbacSeeder::class);
});

it('generates a year of invoices and kickbacks with payment proration offset', function () {
    $fx = monthlyBillingFixture(); // first billing 202609, kb start 202703
    $monthly = app(MonthlyBillingService::class);
    $billing = app(BillingService::class);

    $invoiceMonths = [];
    $cursor = Carbon::createFromFormat('Ym', '202609');
    for ($i = 0; $i < 12; $i++) {
        $invoiceMonths[] = $cursor->format('Ym');
        $cursor->addMonthNoOverflow();
    }

    // 請求12ヶ月分 + キックバックが出るまで（最終請求月+6）バッチ実行
    $batchEnd = Carbon::createFromFormat('Ym', '202609')->addMonthsNoOverflow(11 + 6);
    $batch = Carbon::createFromFormat('Ym', '202609');
    while ($batch->format('Ym') <= $batchEnd->format('Ym')) {
        $stats = $monthly->run($batch->format('Ym'), $fx['admin']);
        expect($stats['errors'])->toBe(0);
        $batch->addMonthNoOverflow();
    }

    $invoices = Invoice::query()
        ->where('contract_id', $fx['contract']->id)
        ->whereIn('billing_year_month', $invoiceMonths)
        ->orderBy('billing_year_month')
        ->get();

    expect($invoices)->toHaveCount(12);

    // 入金パターン: 1ヶ月目 -200、2ヶ月目 +200、他は満額
    $paidPlan = [];
    foreach ($invoices as $index => $invoice) {
        $paid = (int) $invoice->total;
        if ($index === 0) {
            $paid -= 200;
        } elseif ($index === 1) {
            $paid += 200;
        }
        $paidPlan[$invoice->billing_year_month] = $paid;
        $billing->markPaid($fx['admin'], $invoice, $paid);
    }

    $kickbacks = KickbackInvoice::query()
        ->where('contract_id', $fx['contract']->id)
        ->whereIn('billing_year_month', $invoiceMonths)
        ->where('status', '!=', InvoiceStatus::Withdrawn->value)
        ->get();

    // 区間2本 × 12ヶ月
    expect($kickbacks)->toHaveCount(24);

    foreach ($invoices as $invoice) {
        $linked = $kickbacks->where('source_invoice_id', $invoice->id);
        expect($linked)->toHaveCount(2);
        $ratio = $invoice->fresh()->paymentRatio();
        foreach ($linked as $kb) {
            // 再計算後なので manual_adjusted ではない
            expect($kb->fresh()->manual_adjusted)->toBeFalse();
        }
        // 区間ごとに丸めるため、合算は round(合計×率) と1円ずれることがある
        $expectedSubtotal = (int) round(2000 * $ratio) + (int) round(1500 * $ratio);
        $actualSubtotal = (int) $linked->sum(fn ($kb) => $kb->fresh()->subtotal);
        expect($actualSubtotal)->toBe($expectedSubtotal);
    }

    // 1・2ヶ月目の不足/超過は2ヶ月合計で相殺（区間ごとの丸めでも合計は満額×2）
    $firstTwo = $invoices->take(2);
    $firstTwoKbSubtotal = KickbackInvoice::query()
        ->whereIn('source_invoice_id', $firstTwo->pluck('id'))
        ->sum('subtotal');
    expect((int) $firstTwoKbSubtotal)->toBe(3500 * 2);

    // 1年合計も満額12ヶ月と一致
    $yearKbSubtotal = KickbackInvoice::query()
        ->where('contract_id', $fx['contract']->id)
        ->whereIn('billing_year_month', $invoiceMonths)
        ->sum('subtotal');
    expect((int) $yearKbSubtotal)->toBe(3500 * 12);

    // 手動端数調整が動くこと（明細「端数調整」として +1円）
    $sample = $kickbacks->first();
    $before = (int) $sample->fresh()->total;
    app(KickbackService::class)->adjustAmounts($fx['admin'], $sample->fresh(), 1);
    $sample = $sample->fresh('lines');
    expect($sample->manual_adjusted)->toBeTrue()
        ->and($sample->total)->toBe($before + 1)
        ->and($sample->lines->firstWhere('is_adjustment', true)?->description)->toBe('端数調整')
        ->and($sample->lines->firstWhere('is_adjustment', true)?->amount_inclusive)->toBe(1);
});
