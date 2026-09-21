<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemDocument;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function bpItemsFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $child = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'Child');

    $bpUser = User::factory()->bp($root)->create([
        'login_id' => 'BPITEM',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $root->id);

    $admin = User::factory()->admin()->create([
        'login_id' => 'ADMINITEM',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $catalog = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '標準回線',
        'billing_type' => BillingType::Running->value,
        'partition_price' => 1000,
        'recommended_price' => 2000,
        'user_price' => 2500,
    ]);

    return compact('root', 'child', 'bpUser', 'admin', 'catalog');
}

it('lets bp create owned service and set wholesale from item show', function () {
    $fx = bpItemsFixture();

    $this->actingAs($fx['bpUser'], 'bp')
        ->post(route('bp.items.store'), [
            'name' => '現地サポート',
            'billing_type' => BillingType::Running->value,
            'partition_price' => 800,
            'recommended_price' => 1200,
            'user_price' => 1500,
            'tax_rate' => 10,
            'is_active' => 1,
        ])
        ->assertRedirect();

    $owned = Item::query()->where('name', '現地サポート')->first();
    expect($owned)->not->toBeNull()
        ->and($owned->owning_bp_id)->toBe($fx['root']->id);

    $this->actingAs($fx['bpUser'], 'bp')
        ->post(route('bp.items.wholesale.store', $fx['catalog']), [
            'buyer_bp_id' => $fx['child']->id,
            'amount' => 900,
        ])
        ->assertRedirect(route('bp.items.show', $fx['catalog']));

    expect(app(CatalogPricingService::class)->resolveWholesaleAmount(
        $fx['catalog'],
        $fx['root'],
        $fx['child'],
    ))->toBe('900');
});

it('lets bp upload own document template on catalog item', function () {
    Storage::fake('local');
    $fx = bpItemsFixture();

    $file = UploadedFile::fake()->create('guide.html', 20, 'text/html');

    $this->actingAs($fx['bpUser'], 'bp')
        ->post(route('bp.items.documents.store', $fx['catalog']), [
            'title' => 'BP開通案内',
            'file' => $file,
        ])
        ->assertRedirect();

    $doc = ItemDocument::query()->where('title', 'BP開通案内')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->owning_bp_id)->toBe($fx['root']->id)
        ->and($doc->item_id)->toBe($fx['catalog']->id);
});

it('rejects other bp owned item in contract draft', function () {
    $fx = bpItemsFixture();
    $seq = app(NumberSequenceService::class);

    $other = app(CatalogPricingService::class)->createItem($fx['bpUser'], [
        'name' => 'Root専用',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 100,
        'user_price' => 200,
        'owning_bp_id' => $fx['root']->id,
    ]);

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $fx['child']->id,
        'name' => 'ChildCust',
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

    $childUser = User::factory()->bp($fx['child'])->create([
        'login_id' => 'CHILDITEM',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($childUser, 'bp_owner', RoleScope::Bp, $fx['child']->id);

    expect(fn () => app(ContractService::class)->createDraft($childUser, $site, [$other->id]))
        ->toThrow(InvalidArgumentException::class, '他BPの独自サービス');
});
