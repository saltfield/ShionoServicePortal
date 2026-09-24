<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Billing\Services\BpCustomerBillingOverviewService;
use App\Domains\Billing\Services\MonthlyBillingService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function bpBillingTabFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root BP');
    $mid = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'Mid BP');
    $leaf = $hierarchy->createChild($mid, $seq->next(PartnerCodePrefix::Bpn), 'Leaf BP');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $leaf->id,
        'name' => 'Billing Tab Customer',
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

    $admin = User::factory()->admin()->create(['login_id' => 'BILLTABADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);
    $leafUser = User::factory()->bp($leaf)->create(['login_id' => 'BILLTABLEAF', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($leafUser, 'bp_owner', RoleScope::Bp, $leaf->id);
    $midUser = User::factory()->bp($mid)->create(['login_id' => 'BILLTABMID', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($midUser, 'bp_owner', RoleScope::Bp, $mid->id);
    $rootUser = User::factory()->bp($root)->create(['login_id' => 'BILLTABROOT', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($rootUser, 'bp_owner', RoleScope::Bp, $root->id);

    $catalog = app(CatalogPricingService::class);
    $running = $catalog->createItem($admin, [
        'name' => '月額請求タブ',
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
    $contracts->decidePriceApproval($rootUser, $forwarded, true);
    $contracts->activate($leafUser, $contract->fresh(), '202609');

    return [
        'admin' => $admin,
        'root' => $root,
        'mid' => $mid,
        'leaf' => $leaf,
        'contract' => $contract->fresh(['items.priceLayers', 'owningBp']),
        'customer' => $customer,
    ];
}

it('shows customer invoices and kickback preview on bp billing tab for ancestor bp', function () {
    $fx = bpBillingTabFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    $overview = app(BpCustomerBillingOverviewService::class)->forPartner($fx['root']);
    expect($overview['invoice_totals']['count'])->toBe(1)
        ->and($overview['invoice_totals']['subtotal'])->toBe(5000)
        ->and($overview['kickback_preview'])->not->toBeEmpty();

    $previewAmounts = collect($overview['kickback_preview'])->pluck('subtotal')->sort()->values()->all();
    expect($previewAmounts)->toBe([1500, 2000]);

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.business-partners.show', ['businessPartner' => $fx['root'], 'tab' => 'billing']))
        ->assertOk()
        ->assertSee('カスタマー請求（ルート発行）')
        ->assertSee('キックバック金額（月次想定・計算）')
        ->assertSee($fx['customer']->name)
        ->assertSee('5,000');
});

it('includes descendant-managed contracts when viewing mid bp billing tab', function () {
    $fx = bpBillingTabFixture();
    app(MonthlyBillingService::class)->run('202609', $fx['admin']);

    $overview = app(BpCustomerBillingOverviewService::class)->forPartner($fx['mid']);
    expect($overview['invoice_totals']['count'])->toBe(1)
        ->and(collect($overview['kickback_preview'])->contains(fn ($row) => $row['involves_partner']))->toBeTrue();

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.business-partners.show', ['businessPartner' => $fx['mid'], 'tab' => 'billing']))
        ->assertOk()
        ->assertSee('自区間');
});
