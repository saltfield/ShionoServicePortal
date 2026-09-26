<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Enums\ContractStatus;
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

it('shows contract pipeline counts on admin dashboard', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Dash Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Dash Child');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Dash Customer',
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

    $admin = User::factory()->admin()->create([
        'login_id' => 'DASHADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($child)->create([
        'login_id' => 'DASHBP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);

    $parentUser = User::factory()->bp($parent)->create([
        'login_id' => 'DASHPARENT',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $catalog = app(CatalogPricingService::class);
    $initial = $catalog->createItem($admin, [
        'name' => '開通費',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);
    $running = $catalog->createItem($admin, [
        'name' => '月額',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $initial->id,
        'partition_price' => 1000,
        'user_price' => 3000,
    ]);

    $contracts = app(ContractService::class);
    $draft = $contracts->createDraft($bpUser, $site, [$initial->id, $running->id]);

    $pending = $contracts->createDraft($bpUser, $site, [$initial->id, $running->id]);
    $contracts->submitPriceApproval($bpUser, $pending);

    $approved = $contracts->createDraft($bpUser, $site, [$initial->id, $running->id]);
    $app = $contracts->submitPriceApproval($bpUser, $approved);
    $contracts->decidePriceApproval($parentUser, $app, true);

    expect($draft->fresh()->status)->toBe(ContractStatus::Draft)
        ->and($pending->fresh()->status)->toBe(ContractStatus::PendingPriceApproval)
        ->and($approved->fresh()->status)->toBe(ContractStatus::Approved);

    $this->post(route('admin.login.store'), [
        'login_id' => 'DASHADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('管理者画面')
        ->assertSee('対処が必要な項目があります')
        ->assertSee('オーダー作成中')
        ->assertSee('価格申請（未決裁）')
        ->assertSee('承認済・手配中');

    $html = $this->get(route('admin.dashboard'))->getContent();
    expect($html)->toMatch('/オーダー作成中[\s\S]*?>1</')
        ->and($html)->toMatch('/価格申請（未決裁）[\s\S]*?>1</')
        ->and($html)->toMatch('/承認済・手配中[\s\S]*?>1</')
        ->and($html)->toContain('ssp-stat-card--attention');

    $this->get(route('admin.contracts.index', ['status' => 'approved']))
        ->assertOk()
        ->assertSee($approved->code)
        ->assertDontSee($draft->code)
        ->assertSee('古いものから上に表示しています');

    $this->get(route('admin.contracts.index', ['status' => 'pending_price_approval']))
        ->assertOk()
        ->assertSee('申請日時')
        ->assertSee($pending->code);

    $this->get(route('admin.contracts.index', ['status' => 'activated']))
        ->assertOk()
        ->assertSee('提供開始日');

    $this->post(route('admin.logout'));

    $this->post(route('bp.login.store'), [
        'login_id' => 'DASHBP',
        'bpn' => $child->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.dashboard'))
        ->assertOk()
        ->assertSee($child->code)
        ->assertSee('Dash Child');

    $this->post(route('bp.logout'));

    $customerUser = User::factory()->customer($customer)->create([
        'login_id' => 'DASHCUST',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($customerUser, 'customer_owner', RoleScope::Customer, $customer->id);

    $this->post(route('customer.login.store'), [
        'login_id' => 'DASHCUST',
        'cn' => $customer->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('customer.dashboard'))
        ->assertOk()
        ->assertSee($customer->code)
        ->assertSee('Dash Customer');
});
