<?php

use App\Domains\Auth\Enums\EntityType;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\BusinessPartner;
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

function masterAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'MASTERADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('creates customer and sites with independent billing address', function () {
    masterAdmin();
    $this->post(route('admin.login.store'), [
        'login_id' => 'MASTERADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Manage BP',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $bp = BusinessPartner::query()->where('name', 'Manage BP')->first();

    $this->post(route('admin.customers.store'), [
        'managing_bp_id' => $bp->id,
        'name' => 'Acme Customer',
        'entity_type' => EntityType::Corporate->value,
        'two_factor_mode' => TwoFactorMode::Optional->value,
        'is_active' => '1',
    ])->assertRedirect();

    $customer = Customer::query()->where('name', 'Acme Customer')->first();
    expect($customer->code)->toStartWith('CN')
        ->and(AuditLog::query()->where('action', 'customer.create')->exists())->toBeTrue();

    $this->post(route('admin.sites.store', $customer), [
        'name' => '東京拠点',
        'postal_code' => '100-0001',
        'address' => '千代田区1-1',
        'building_name' => 'テストビル101',
        'phone' => '03-1111-1111',
        'billing_name' => '請求宛名A',
        'billing_department' => '経理',
        'billing_postal_code' => '200-0001',
        'billing_address' => '横浜市1-1',
        'billing_building_name' => '請求ビル2F',
        'billing_phone' => '045-111-1111',
        'is_primary' => '1',
        'is_active' => '1',
    ])->assertRedirect();

    $primary = Site::query()->where('name', '東京拠点')->first();
    expect($primary->is_primary)->toBeTrue()
        ->and($primary->address)->toBe('千代田区1-1')
        ->and($primary->building_name)->toBe('テストビル101')
        ->and($primary->billing_address)->toBe('横浜市1-1')
        ->and($primary->billing_building_name)->toBe('請求ビル2F');

    $this->post(route('admin.sites.store', $customer), [
        'name' => '大阪拠点',
        'postal_code' => '530-0001',
        'address' => '大阪市1-1',
        'billing_name' => '請求宛名B',
        'billing_address' => '神戸市1-1',
        'is_primary' => '0',
        'is_active' => '1',
    ])->assertRedirect();

    expect(Site::query()->where('customer_id', $customer->id)->count())->toBe(2)
        ->and($primary->fresh()->is_primary)->toBeTrue()
        ->and(Site::query()->where('name', '大阪拠点')->first()->is_primary)->toBeFalse();
});

it('requires matching confirmation code to delete customer', function () {
    masterAdmin();
    $this->post(route('admin.login.store'), [
        'login_id' => 'MASTERADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Delete BP',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $bp = BusinessPartner::query()->where('name', 'Delete BP')->first();

    $this->post(route('admin.customers.store'), [
        'managing_bp_id' => $bp->id,
        'name' => 'Delete Target',
        'entity_type' => EntityType::Corporate->value,
        'two_factor_mode' => TwoFactorMode::Optional->value,
        'is_active' => '1',
    ]);
    $customer = Customer::query()->where('name', 'Delete Target')->first();

    $this->get(route('admin.customers.show', $customer))->assertOk();
    $code = session('customer_delete_confirm.'.$customer->id);
    expect($code)->toMatch('/^\d{4}$/');

    $this->from(route('admin.customers.show', $customer))
        ->delete(route('admin.customers.destroy', $customer), [
            'confirmation_code' => '0000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation_code');

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeTrue();

    $this->get(route('admin.customers.show', $customer));
    $code = session('customer_delete_confirm.'.$customer->id);

    $this->delete(route('admin.customers.destroy', $customer), [
        'confirmation_code' => $code,
    ])->assertRedirect();

    expect(Customer::query()->whereKey($customer->id)->exists())->toBeFalse();
});

it('requires matching confirmation code to delete site', function () {
    masterAdmin();
    $this->post(route('admin.login.store'), [
        'login_id' => 'MASTERADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Site Delete BP',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $bp = BusinessPartner::query()->where('name', 'Site Delete BP')->first();

    $this->post(route('admin.customers.store'), [
        'managing_bp_id' => $bp->id,
        'name' => 'Site Delete Customer',
        'entity_type' => EntityType::Corporate->value,
        'two_factor_mode' => TwoFactorMode::Optional->value,
        'is_active' => '1',
    ]);
    $customer = Customer::query()->where('name', 'Site Delete Customer')->first();

    $this->post(route('admin.sites.store', $customer), [
        'name' => '削除対象拠点',
        'postal_code' => '100-0001',
        'address' => '千代田区',
        'billing_name' => '請求先',
        'billing_address' => '港区',
        'is_primary' => '1',
        'is_active' => '1',
    ]);
    $site = Site::query()->where('name', '削除対象拠点')->first();

    $this->get(route('admin.sites.edit', $site))->assertOk();
    $code = session('site_delete_confirm.'.$site->id);
    expect($code)->toMatch('/^\d{4}$/');

    $this->from(route('admin.sites.edit', $site))
        ->delete(route('admin.sites.destroy', $site), [
            'confirmation_code' => '9999',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation_code');

    expect(Site::query()->whereKey($site->id)->exists())->toBeTrue();

    $this->get(route('admin.sites.edit', $site));
    $code = session('site_delete_confirm.'.$site->id);

    $this->delete(route('admin.sites.destroy', $site), [
        'confirmation_code' => $code,
    ])->assertRedirect();

    expect(Site::query()->whereKey($site->id)->exists())->toBeFalse();
});

it('requires matching confirmation code to delete business partner', function () {
    masterAdmin();
    $this->post(route('admin.login.store'), [
        'login_id' => 'MASTERADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Parent For Delete',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $parent = BusinessPartner::query()->where('name', 'Parent For Delete')->first();

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Child To Delete',
        'parent_id' => $parent->id,
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $child = BusinessPartner::query()->where('name', 'Child To Delete')->first();

    $this->get(route('admin.business-partners.show', $child))->assertOk();
    $code = session('bp_delete_confirm.'.$child->id);
    expect($code)->toMatch('/^\d{4}$/');

    $this->from(route('admin.business-partners.show', $child))
        ->delete(route('admin.business-partners.destroy', $child), [
            'confirmation_code' => '0000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation_code');

    expect(BusinessPartner::query()->whereKey($child->id)->exists())->toBeTrue();

    $this->get(route('admin.business-partners.show', $child));
    $code = session('bp_delete_confirm.'.$child->id);

    $this->delete(route('admin.business-partners.destroy', $child), [
        'confirmation_code' => $code,
    ])->assertRedirect();

    expect(BusinessPartner::query()->whereKey($child->id)->exists())->toBeFalse();
});
