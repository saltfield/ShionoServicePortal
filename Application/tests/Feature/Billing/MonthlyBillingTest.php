<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Billing\Services\BillingScheduleService;
use App\Domains\Billing\Services\KickbackService;
use App\Domains\Billing\Services\MonthlyBillingService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Billing\Enums\BillingBatchRunStatus;
use App\Models\BillingBatchError;
use App\Models\BillingBatchRun;
use App\Models\ContractItemPriceLayer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\KickbackInvoice;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function monthlyBillingFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'KB Root');
    $mid = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'KB Mid');
    $leaf = $hierarchy->createChild($mid, $seq->next(PartnerCodePrefix::Bpn), 'KB Leaf');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $leaf->id,
        'name' => 'KB Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社',
        'billing_name' => '請求先',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'KBADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);
    $leafUser = User::factory()->bp($leaf)->create(['login_id' => 'KBLEAF', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($leafUser, 'bp_owner', RoleScope::Bp, $leaf->id);
    $midUser = User::factory()->bp($mid)->create(['login_id' => 'KBMID', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($midUser, 'bp_owner', RoleScope::Bp, $mid->id);
    $rootUser = User::factory()->bp($root)->create(['login_id' => 'KBROOT', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($rootUser, 'bp_owner', RoleScope::Bp, $root->id);

    $catalog = app(CatalogPricingService::class);
    $running = $catalog->createItem($admin, [
        'name' => '月額KB',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 5000,
        'tax_rate' => 10,
    ]);
    $catalog->upsertWholesalePrice($midUser, $running, $mid, $leaf, 3000);
    $catalog->upsertWholesalePrice($rootUser, $running, $root, $mid, 1500);

    $contracts = app(ContractService::class);
    $contract = $contracts->createDraft($leafUser, $site, [$running->id]);
    $contract->items->first()->update(['unit_price' => 5000, 'partition_price' => 3000]);
    $app = $contracts->submitPriceApproval($leafUser, $contract->fresh());
    $forwarded = $contracts->decidePriceApproval($midUser, $app, true);
    expect($contract->fresh()->status)->toBe(ContractStatus::PendingPriceApproval)
        ->and($forwarded->status)->toBe(ApplicationStatus::Pending)
        ->and($forwarded->approvalProgressLabel())->toBe('2/2');
    $contracts->decidePriceApproval($rootUser, $forwarded, true);
    $contracts->activate($leafUser, $contract->fresh(), '202609');

    return [
        'admin' => $admin,
        'root' => $root,
        'mid' => $mid,
        'leaf' => $leaf,
        'leafUser' => $leafUser,
        'midUser' => $midUser,
        'rootUser' => $rootUser,
        'contract' => $contract->fresh(['items.priceLayers', 'owningBp']),
        'customer' => $customer,
    ];
}

it('snapshots price layers on approval', function () {
    $fx = monthlyBillingFixture();
    $layers = ContractItemPriceLayer::query()->where('contract_item_id', $fx['contract']->items->first()->id)->get();

    expect($layers)->toHaveCount(2);
});

it('generates customer invoice for issuer root and owning leaf', function () {
    $fx = monthlyBillingFixture();
    $stats = app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    expect($stats['invoices'])->toBe(1)
        ->and($stats['errors'])->toBe(0)
        ->and($stats['status'])->toBe(BillingBatchRunStatus::Success->value);

    $invoice = Invoice::query()->first();
    expect($invoice->issuer_bp_id)->toBe($fx['root']->id)
        ->and($invoice->owning_bp_id)->toBe($fx['leaf']->id)
        ->and($invoice->billing_year_month)->toBe('202609')
        ->and($invoice->due_year_month)->toBe('202610')
        ->and($invoice->status->label())->toBe('発行済');

    $run = BillingBatchRun::query()->find($stats['run_id']);
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(BillingBatchRunStatus::Success)
        ->and($run->invoices_count)->toBe(1)
        ->and($run->errors_count)->toBe(0)
        ->and($run->actor_user_id)->toBe($fx['admin']->id);
});

it('generates multi-tier kickbacks from sixth billing month', function () {
    $fx = monthlyBillingFixture();
    // first billing 202609 → kickback batch start = 202609 + 6 = 202703
    // batch 202703 looks at source invoice 202609
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);
    expect(KickbackInvoice::query()->count())->toBe(0);

    $source = Invoice::query()->where('billing_year_month', '202609')->first();
    expect($source)->not->toBeNull();

    $stats = app(MonthlyBillingService::class)->run('202703', $fx['admin']);
    expect($stats['kickbacks'])->toBe(2)
        ->and($stats['errors'])->toBe(0);

    $rootToMid = KickbackInvoice::query()->where('from_bp_id', $fx['root']->id)->where('to_bp_id', $fx['mid']->id)->first();
    $midToLeaf = KickbackInvoice::query()->where('from_bp_id', $fx['mid']->id)->where('to_bp_id', $fx['leaf']->id)->first();

    // unpaid → zero amounts, but records exist and link to source invoice
    expect($rootToMid->source_invoice_id)->toBe($source->id)
        ->and($midToLeaf->source_invoice_id)->toBe($source->id)
        ->and($rootToMid->subtotal)->toBe(0)
        ->and($midToLeaf->subtotal)->toBe(0)
        ->and($rootToMid->billing_year_month)->toBe('202609');

    app(BillingService::class)->markPaid($fx['admin'], $source->fresh(), (int) $source->total);

    expect($midToLeaf->fresh()->subtotal)->toBe(2000) // 5000-3000
        ->and($rootToMid->fresh()->subtotal)->toBe(1500); // 3000-1500
});

it('scales kickbacks by paid amount and allows admin amount adjust', function () {
    $fx = monthlyBillingFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);
    app(MonthlyBillingService::class)->run('202703', $fx['admin']);

    $source = Invoice::query()->where('billing_year_month', '202609')->firstOrFail();
    // half payment (tax inclusive)
    $half = (int) round($source->total / 2);
    app(BillingService::class)->markPaid($fx['admin'], $source, $half);

    $midToLeaf = KickbackInvoice::query()->where('from_bp_id', $fx['mid']->id)->where('to_bp_id', $fx['leaf']->id)->firstOrFail();
    expect($midToLeaf->subtotal)->toBe(1000); // 2000 * 0.5

    $beforeTotal = (int) $midToLeaf->fresh()->total;
    app(KickbackService::class)->adjustAmounts($fx['admin'], $midToLeaf, 1);

    expect($midToLeaf->fresh()->manual_adjusted)->toBeTrue()
        ->and($midToLeaf->fresh()->total)->toBe($beforeTotal + 1)
        ->and($midToLeaf->fresh()->lines->firstWhere('is_adjustment', true)?->amount_inclusive)->toBe(1);

    // payment update force-recalculates and clears manual adjust
    app(BillingService::class)->updatePaidAmount($fx['admin'], $source->fresh(), (int) $source->total);
    expect($midToLeaf->fresh()->manual_adjusted)->toBeFalse()
        ->and($midToLeaf->fresh()->subtotal)->toBe(2000)
        ->and($midToLeaf->fresh()->lines->firstWhere('is_adjustment', true))->toBeNull();
});

it('withdraws linked kickbacks when source invoice is withdrawn', function () {
    $fx = monthlyBillingFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);
    app(MonthlyBillingService::class)->run('202703', $fx['admin']);

    $source = Invoice::query()->where('billing_year_month', '202609')->firstOrFail();
    expect(KickbackInvoice::query()->where('status', '!=', InvoiceStatus::Withdrawn->value)->count())->toBe(2);

    app(BillingService::class)->withdraw($fx['admin'], $source);

    expect(KickbackInvoice::query()->where('status', InvoiceStatus::Withdrawn->value)->count())->toBe(2)
        ->and($source->fresh()->status)->toBe(InvoiceStatus::Withdrawn);
});

it('records negative kickback as batch error and continues', function () {
    $fx = monthlyBillingFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    $line = $fx['contract']->items->first();
    $line->update(['unit_price' => 1000]); // less than mid->leaf wholesale 3000
    ContractItemPriceLayer::query()->where('contract_item_id', $line->id)->delete();
    app(\App\Domains\Contract\Services\ContractPriceLayerService::class)->syncForContract($fx['contract']->fresh(['owningBp', 'items.item']));

    $stats = app(MonthlyBillingService::class)->run('202703', $fx['admin']);
    expect($stats['errors'])->toBeGreaterThan(0)
        ->and($stats['status'])->not->toBe(BillingBatchRunStatus::Success->value)
        ->and(BillingBatchError::query()->where('phase', 'kickback')->exists())->toBeTrue();

    $error = BillingBatchError::query()->where('phase', 'kickback')->first();
    $run = BillingBatchRun::query()->find($stats['run_id']);
    expect($error->billing_batch_run_id)->toBe($run->id)
        ->and($run->errors_count)->toBeGreaterThan(0)
        ->and(in_array($run->status, [BillingBatchRunStatus::Partial, BillingBatchRunStatus::Failed], true))->toBeTrue();
});

it('shows generation history on batch settings page', function () {
    $fx = monthlyBillingFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.billing-batch.edit'))
        ->assertOk()
        ->assertSee('生成履歴')
        ->assertSee('成功')
        ->assertSee('202609')
        ->assertSee('請求一覧');
});

it('lists invoices created by a batch run', function () {
    $fx = monthlyBillingFixture();
    $stats = app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    $invoice = Invoice::query()->first();
    expect($invoice->billing_batch_run_id)->toBe($stats['run_id']);

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.billing-batch.runs.show', $stats['run_id']))
        ->assertOk()
        ->assertSee('作成された請求書')
        ->assertSee($invoice->code)
        ->assertSee($fx['customer']->name);
});

it('resolves nonexistent day_of_month to month end for schedule', function () {
    $service = app(BillingScheduleService::class);
    $service->updateSchedule([
        'enabled' => true,
        'day_mode' => 'day_of_month',
        'day_of_month' => 31,
        'time' => '10:00',
        'timezone' => 'Asia/Tokyo',
    ]);

    $feb28 = Carbon::parse('2026-02-28 10:00:00', 'Asia/Tokyo');
    expect($service->shouldRunAt($feb28))->toBeTrue();

    $feb27 = Carbon::parse('2026-02-27 10:00:00', 'Asia/Tokyo');
    expect($service->shouldRunAt($feb27))->toBeFalse();
});

it('runs scheduled batch after configured time once per billing month', function () {
    $fx = monthlyBillingFixture();
    $service = app(BillingScheduleService::class);
    $service->updateSchedule([
        'enabled' => true,
        'day_mode' => 'day_of_month',
        'day_of_month' => 22,
        'time' => '17:25',
        'timezone' => 'Asia/Tokyo',
    ]);

    $before = Carbon::parse('2026-09-22 17:24:59', 'Asia/Tokyo');
    expect(app(MonthlyBillingService::class)->runIfScheduled($before))->toBeNull();

    $after = Carbon::parse('2026-09-22 17:26:00', 'Asia/Tokyo');
    $stats = app(MonthlyBillingService::class)->runIfScheduled($after);
    expect($stats)->not->toBeNull()
        ->and($stats['billing_year_month'])->toBe('202609')
        ->and($stats['status'])->toBe(BillingBatchRunStatus::Success->value);

    expect(app(MonthlyBillingService::class)->runIfScheduled($after->copy()->addMinute()))->toBeNull();

    $run = BillingBatchRun::query()->where('trigger', 'scheduled')->first();
    expect($run)->not->toBeNull()
        ->and($run->actor_user_id)->toBeNull();
});

it('generates invoices for a past month range scoped to bp tree, bp alone, or customer', function () {
    $fx = monthlyBillingFixture();
    $fx['contract']->forceFill(['first_billing_year_month' => '202506'])->save();

    // 別系統の契約（範囲外になること）
    $seq = app(NumberSequenceService::class);
    $otherBp = app(BpHierarchyService::class)->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Other Root');
    $otherCustomer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $otherBp->id,
        'name' => 'Other Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $otherSite = Site::query()->create([
        'customer_id' => $otherCustomer->id,
        'name' => '他社',
        'billing_name' => '請求先',
        'is_primary' => true,
        'is_active' => true,
    ]);
    $otherUser = User::factory()->bp($otherBp)->create([
        'login_id' => 'KBOTHER',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($otherUser, 'bp_owner', RoleScope::Bp, $otherBp->id);
    $otherItem = app(CatalogPricingService::class)->createItem($fx['admin'], [
        'name' => '他系統月額',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 3000,
        'tax_rate' => 10,
    ]);
    $otherContract = app(ContractService::class)->createDraft($otherUser, $otherSite, [$otherItem->id]);
    $otherContract->items->first()->update(['unit_price' => 3000, 'partition_price' => 1000]);
    $otherContract->forceFill([
        'status' => ContractStatus::Activated,
        'activated_at' => now(),
        'first_billing_year_month' => '202506',
        'auto_invoice_enabled' => true,
        'billing_suspended' => false,
    ])->save();

    // 親BP単体では配下 leaf の契約は対象外
    $rootOnly = app(MonthlyBillingService::class)->runRange(
        '202506',
        '202506',
        ['type' => 'bp', 'id' => $fx['root']->id],
        $fx['admin'],
    );
    expect($rootOnly['invoices'])->toBe(0)
        ->and(Invoice::query()->where('contract_id', $fx['contract']->id)->count())->toBe(0);

    // 親BP（配下含む）なら leaf 契約が対象
    $treeStats = app(MonthlyBillingService::class)->runRange(
        '202506',
        '202508',
        ['type' => 'bp_tree', 'id' => $fx['root']->id],
        $fx['admin'],
    );

    expect($treeStats['scope']['type'])->toBe('bp_tree')
        ->and($treeStats['months'])->toHaveCount(3)
        ->and($treeStats['invoices'])->toBe(3)
        ->and($treeStats['errors'])->toBe(0)
        ->and(Invoice::query()->where('contract_id', $fx['contract']->id)->count())->toBe(3)
        ->and(Invoice::query()->where('contract_id', $otherContract->id)->count())->toBe(0);

    $again = app(MonthlyBillingService::class)->runRange(
        '202508',
        '202508',
        ['type' => 'bp', 'id' => $fx['leaf']->id],
        $fx['admin'],
    );
    expect($again['invoices'])->toBe(0)
        ->and($again['errors'])->toBeGreaterThan(0);

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.billing-batch.edit'))
        ->assertOk()
        ->assertSee('BP（配下含む）')
        ->assertSee('BP単体')
        ->assertSee('カスタマー');

    $this->actingAs($fx['admin'], 'admin')
        ->post(route('admin.billing-batch.run-range'), [
            'from_year_month' => '202509',
            'to_year_month' => '202509',
            'scope_type' => 'customer',
            'customer_id' => $fx['customer']->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Invoice::query()->where('contract_id', $fx['contract']->id)->where('billing_year_month', '202509')->exists())->toBeTrue()
        ->and(Invoice::query()->where('contract_id', $otherContract->id)->count())->toBe(0);
});

it('rejects inverted billing month range', function () {
    expect(fn () => app(MonthlyBillingService::class)->yearMonthsBetween('202508', '202506'))
        ->toThrow(InvalidArgumentException::class);
});

it('requires valid scope type for range generation', function () {
    expect(fn () => app(MonthlyBillingService::class)->normalizeScope(['type' => 'unknown']))
        ->toThrow(InvalidArgumentException::class);
});
