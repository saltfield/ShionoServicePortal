<?php

use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\BusinessPartner;
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

it('allows admin to create item via http', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ITEMADMIN',
        'password' => 'Password123!',
    ]);

    $type = \App\Models\ItemType::query()->create([
        'name' => 'テスト種別',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    $this->post(route('admin.items.store'), [
        'name' => '光回線イニシャル',
        'billing_type' => BillingType::Initial->value,
        'item_type_id' => $type->id,
        'partition_price' => 3000,
        'recommended_price' => 5000,
        'user_price' => 5000,
        'tax_rate' => 10,
        'is_active' => '1',
    ])->assertRedirect();

    $initial = Item::query()->where('name', '光回線イニシャル')->first();
    expect($initial)->not->toBeNull()
        ->and($initial->billing_type)->toBe(BillingType::Initial);

    $this->post(route('admin.items.store'), [
        'name' => '光回線月額',
        'billing_type' => BillingType::Running->value,
        'item_type_id' => $type->id,
        'required_item_id' => $initial->id,
        'partition_price' => 2000,
        'recommended_price' => 4000,
        'user_price' => 4500,
        'tax_rate' => 10,
        'is_active' => '1',
    ])->assertRedirect();

    $running = Item::query()->where('name', '光回線月額')->first();
    expect($running->required_item_id)->toBe($initial->id)
        ->and(AuditLog::query()->where('action', 'item.create')->count())->toBe(2);

    $this->get(route('admin.items.index'))->assertOk()->assertSee('光回線月額');
});

it('allows admin to open item edit and update', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMEDITADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $type = \App\Models\ItemType::query()->create([
        'name' => '編集用種別',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);

    $item = Item::factory()->create([
        'name' => '編集前品目',
        'item_type_id' => $type->id,
        'billing_type' => BillingType::Running,
        'partition_price' => 1000,
        'recommended_price' => 2000,
        'user_price' => 2500,
    ]);

    $this->actingAs($user, 'admin')
        ->get(route('admin.items.edit', $item))
        ->assertOk()
        ->assertSee('編集前品目')
        ->assertSee('編集用種別');

    $this->actingAs($user, 'admin')
        ->put(route('admin.items.update', $item), [
            'name' => '編集後品目',
            'billing_type' => BillingType::Running->value,
            'item_type_id' => $type->id,
            'partition_price' => 1100,
            'recommended_price' => 2100,
            'user_price' => 2600,
            'tax_rate' => 10,
            'is_active' => '1',
        ])
        ->assertRedirect(route('admin.items.show', $item))
        ->assertSessionHas('status');

    expect($item->fresh()->name)->toBe('編集後品目')
        ->and((float) $item->fresh()->partition_price)->toBe(1100.0);
});

it('allows admin to open item edit when current item type is inactive', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMEDITINACTIVE',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $inactive = \App\Models\ItemType::query()->create([
        'name' => '無効種別',
        'message' => null,
        'is_active' => false,
        'owning_bp_id' => null,
    ]);

    $item = Item::factory()->create([
        'name' => '無効種別付き品目',
        'item_type_id' => $inactive->id,
    ]);

    $this->actingAs($user, 'admin')
        ->get(route('admin.items.edit', $item))
        ->assertOk()
        ->assertSee('無効種別');
});

it('accepts only excel xml html for document templates', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMDOCADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ITEMDOCADMIN',
        'password' => 'Password123!',
    ]);

    $item = Item::factory()->create();

    $this->post(route('admin.items.documents.store', $item), [
        'title' => '開通案内',
        'file' => Illuminate\Http\UploadedFile::fake()->create('guide.html', 10, 'text/html'),
    ])->assertRedirect()->assertSessionHas('status');

    expect($item->documents()->count())->toBe(1);

    $this->from(route('admin.items.show', $item))
        ->post(route('admin.items.documents.store', $item), [
            'title' => 'PDFは不可',
            'file' => Illuminate\Http\UploadedFile::fake()->create('guide.pdf', 10, 'application/pdf'),
        ])
        ->assertRedirect(route('admin.items.show', $item))
        ->assertSessionHasErrors('file');

    expect($item->documents()->count())->toBe(1);
});

it('requires confirmation code to delete document templates', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ITEMDOCDEL',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ITEMDOCDEL',
        'password' => 'Password123!',
    ]);

    $item = Item::factory()->create();

    $this->post(route('admin.items.documents.store', $item), [
        'title' => '開通案内',
        'file' => Illuminate\Http\UploadedFile::fake()->create('guide.html', 10, 'text/html'),
    ])->assertRedirect();

    $document = $item->documents()->first();
    expect($document)->not->toBeNull();

    $this->get(route('admin.items.show', $item))->assertOk();
    $code = session('item_document_delete_confirm.'.$document->id);
    expect($code)->toMatch('/^\d{4}$/');

    $this->from(route('admin.items.show', $item))
        ->delete(route('admin.items.documents.destroy', $document), [
            'confirmation_code' => '0000',
        ])
        ->assertRedirect(route('admin.items.show', $item))
        ->assertSessionHasErrors('confirmation_code');

    expect($item->documents()->count())->toBe(1);

    $this->get(route('admin.items.show', $item))->assertOk();
    $code = session('item_document_delete_confirm.'.$document->id);

    $this->delete(route('admin.items.documents.destroy', $document), [
        'confirmation_code' => $code,
    ])->assertRedirect()->assertSessionHas('status');

    expect($item->documents()->count())->toBe(0);
});
