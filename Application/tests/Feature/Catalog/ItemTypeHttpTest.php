<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemType;
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

function itemTypeAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMTYPEADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('allows admin to manage system item types', function () {
    $admin = itemTypeAdmin();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.item-types.store'), [
            'name' => '光回線',
            'message' => '開通まで約2週間かかります',
            'is_active' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $type = ItemType::query()->where('name', '光回線')->first();
    expect($type)->not->toBeNull()
        ->and($type->owning_bp_id)->toBeNull()
        ->and($type->message)->toBe('開通まで約2週間かかります');

    $this->actingAs($admin, 'admin')
        ->put(route('admin.item-types.update', $type), [
            'name' => '光回線（改定）',
            'message' => '開通まで約3週間かかります',
            'is_active' => '1',
        ])
        ->assertRedirect();

    expect($type->fresh()->name)->toBe('光回線（改定）');
});

it('allows bp to manage own item types only', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $bpUser = User::factory()->bp($bp)->create([
        'login_id' => 'ITEMTYPEBP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $bp->id);

    $system = ItemType::query()->create([
        'name' => 'システム光回線',
        'message' => '標準案内',
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    $this->actingAs($bpUser, 'bp')
        ->post(route('bp.item-types.store'), [
            'name' => 'BP独自',
            'message' => '自社サービス注意',
            'is_active' => '1',
        ])
        ->assertRedirect();

    $own = ItemType::query()->where('name', 'BP独自')->first();
    expect($own)->not->toBeNull()
        ->and((int) $own->owning_bp_id)->toBe((int) $bp->id);

    $this->actingAs($bpUser, 'bp')
        ->get(route('bp.item-types.index'))
        ->assertOk()
        ->assertSee('BP独自')
        ->assertDontSee('システム光回線');

    $this->actingAs($bpUser, 'bp')
        ->put(route('bp.item-types.update', $system), [
            'name' => '改ざん',
            'message' => 'x',
            'is_active' => '1',
        ])
        ->assertNotFound();
});

it('requires item type when creating item via http', function () {
    $admin = itemTypeAdmin();
    $type = ItemType::query()->create([
        'name' => '必須種別',
        'message' => '案内あり',
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.items.store'), [
            'name' => '種別なし品目',
            'billing_type' => BillingType::Running->value,
            'partition_price' => 1000,
            'recommended_price' => 2000,
            'user_price' => 3000,
            'tax_rate' => 10,
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('item_type_id');

    $this->actingAs($admin, 'admin')
        ->post(route('admin.items.store'), [
            'name' => '種別あり品目',
            'billing_type' => BillingType::Running->value,
            'item_type_id' => $type->id,
            'partition_price' => 1000,
            'recommended_price' => 2000,
            'user_price' => 3000,
            'tax_rate' => 10,
            'is_active' => '1',
        ])
        ->assertRedirect();

    $item = Item::query()->where('name', '種別あり品目')->first();
    expect($item)->not->toBeNull()
        ->and((int) $item->item_type_id)->toBe((int) $type->id);
});

it('shows item type guide message data on order create', function () {
    $admin = itemTypeAdmin();
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'name' => '案内テスト顧客',
        'managing_bp_id' => $bp->id,
        'entity_type' => 'corporate',
        'two_factor_mode' => 'optional',
        'is_active' => true,
    ]);
    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社',
        'billing_name' => '請求先',
        'billing_address' => '東京都',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $type = ItemType::query()->create([
        'name' => '光回線',
        'message' => '開通まで約2週間かかります',
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    Item::factory()->create([
        'name' => '案内対象品目',
        'description' => 'この品目の詳細説明です',
        'billing_type' => BillingType::Running,
        'item_type_id' => $type->id,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.contracts.create', [
            'customer_id' => $customer->id,
            'site_id' => $site->id,
        ]))
        ->assertOk()
        ->assertSee('order-wizard', false)
        ->assertSee('価格承認申請')
        ->assertSee('item-type-notices', false)
        ->assertSee('開通まで約2週間かかります', false)
        ->assertSee('data-type-message', false)
        ->assertSee('js-item-description', false)
        ->assertSee('この品目の詳細説明です', false)
        ->assertDontSee('window.alert', false);
});
