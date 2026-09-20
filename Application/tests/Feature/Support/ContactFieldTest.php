<?php

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\BusinessPartner;
use App\Models\User;
use App\Support\ContactFieldRules;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

it('normalizes postal codes to hyphenated form', function () {
    expect(ContactFieldRules::normalizePostalCode('1000001'))->toBe('100-0001')
        ->and(ContactFieldRules::normalizePhone('03あ1111'))->toBe('031111');
});

it('rejects japanese characters in postal code and phone on customer create', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'CONTACTADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'CONTACTADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Contact BP',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $bp = BusinessPartner::query()->where('name', 'Contact BP')->first();

    $this->from(route('admin.customers.create'))
        ->post(route('admin.customers.store'), [
            'managing_bp_id' => $bp->id,
            'name' => 'Bad Contact',
            'entity_type' => EntityType::Corporate->value,
            'two_factor_mode' => 'optional',
            'postal_code' => '１００−０００１',
            'phone' => '０３−１２３４−５６７８',
            'is_active' => '1',
        ])
        ->assertRedirect(route('admin.customers.create'))
        ->assertSessionHasErrors(['postal_code', 'phone']);
});

it('looks up address from postal code when authenticated', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'ZIPADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ZIPADMIN',
        'password' => 'Password123!',
    ]);

    Http::fake([
        'zipcloud.ibsnet.co.jp/*' => Http::response([
            'results' => [[
                'address1' => '東京都',
                'address2' => '千代田区',
                'address3' => '千代田',
            ]],
        ]),
    ]);

    $this->getJson(route('postal.lookup', ['zipcode' => '1000001']))
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'postal_code' => '100-0001',
            'address' => '東京都千代田区千代田',
        ]);
});
