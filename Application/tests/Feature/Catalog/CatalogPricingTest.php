<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function catalogAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'CATALOGADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('creates item with billing type and required companion', function () {
    $admin = catalogAdmin();
    $service = app(CatalogPricingService::class);

    $initial = $service->createItem($admin, [
        'name' => '光回線イニシャル',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 3000,
        'recommended_price' => 5000,
        'user_price' => 5000,
    ]);

    $running = $service->createItem($admin, [
        'name' => '光回線月額',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $initial->id,
        'partition_price' => 2000,
        'recommended_price' => 4000,
        'user_price' => 4500,
    ]);

    expect($initial->code)->toMatch('/^\d{7}$/')
        ->and($initial->billing_type)->toBe(BillingType::Initial)
        ->and($running->required_item_id)->toBe($initial->id)
        ->and(AuditLog::query()->where('action', 'item.create')->count())->toBe(2);
});

it('rejects required item cycle and self reference', function () {
    $admin = catalogAdmin();
    $service = app(CatalogPricingService::class);

    $a = $service->createItem($admin, [
        'name' => 'A',
        'billing_type' => BillingType::Initial->value,
    ]);
    $b = $service->createItem($admin, [
        'name' => 'B',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $a->id,
    ]);

    expect(fn () => $service->updateItem($admin, $a, [
        'name' => 'A',
        'billing_type' => BillingType::Initial->value,
        'required_item_id' => $b->id,
    ]))->toThrow(InvalidArgumentException::class, '循環');

    expect(fn () => $service->updateItem($admin, $a, [
        'name' => 'A',
        'billing_type' => BillingType::Initial->value,
        'required_item_id' => $a->id,
    ]))->toThrow(InvalidArgumentException::class, '自分自身');
});

it('allows bp to set wholesale only for direct child', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $child = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'Child');
    $grand = $hierarchy->createChild($child, $seq->next(PartnerCodePrefix::Bpn), 'Grand');

    $bpUser = User::factory()->bp($root)->create();
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $root->id);

    $admin = catalogAdmin();
    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '回線',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);

    $service = app(CatalogPricingService::class);
    $price = $service->upsertWholesalePrice($bpUser, $item, $root, $child, 1500);

    expect($price->amount)->toBe(1500)
        ->and($service->resolveWholesaleAmount($item, $root, $child))->toBe('1500')
        ->and($service->resolveWholesaleAmount($item, $root, $grand))->toBe('1000');

    expect(fn () => $service->upsertWholesalePrice($bpUser, $item, $root, $grand, 1200))
        ->toThrow(InvalidArgumentException::class, '直接の子');
});

it('sets customer price and falls back to user_price', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $root->id,
        'name' => 'Cust',
        'entity_type' => 'corporate',
        'two_factor_mode' => 'optional',
        'is_active' => true,
    ]);

    $bpUser = User::factory()->bp($root)->create();
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $root->id);

    $admin = catalogAdmin();
    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '回線',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'user_price' => 3000,
    ]);

    $service = app(CatalogPricingService::class);
    expect($service->resolveCustomerAmount($item, $customer, $root))->toBe('3000');

    $service->upsertCustomerPrice($bpUser, $item, $customer, 2800);
    expect($service->resolveCustomerAmount($item, $customer, $root))->toBe('2800');
});

it('rejects item mutation by bp user', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $bpUser = User::factory()->bp($root)->create();
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $root->id);

    expect(fn () => app(CatalogPricingService::class)->createItem($bpUser, [
        'name' => 'NG',
        'billing_type' => BillingType::Initial->value,
    ]))->toThrow(InvalidArgumentException::class, '管理者のみ');
});
