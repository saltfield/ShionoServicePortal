<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\BpWholesalePrice;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

it('lets bp edit wholesale amounts in buyer item list', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $child = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'Child');

    $bpUser = User::factory()->bp($root)->create([
        'login_id' => 'WSLISTBP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $root->id);

    $admin = User::factory()->admin()->create([
        'login_id' => 'WSLISTADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '回線',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);

    $this->post(route('bp.login.store'), [
        'login_id' => 'WSLISTBP',
        'bpn' => $root->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.prices.wholesale.index', ['buyer_bp_id' => $child->id]))
        ->assertOk()
        ->assertSee('仕切り一覧')
        ->assertSee($item->code)
        ->assertSee('一覧を保存');

    $this->post(route('bp.prices.wholesale.store'), [
        'buyer_bp_id' => $child->id,
        'amounts' => [
            $item->id => 1234,
        ],
    ])->assertRedirect(route('bp.prices.wholesale.index', ['buyer_bp_id' => $child->id]));

    expect(BpWholesalePrice::query()
        ->where('item_id', $item->id)
        ->where('seller_bp_id', $root->id)
        ->where('buyer_bp_id', $child->id)
        ->value('amount'))->toBe(1234);
});
