<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAdminUser(array $overrides = []): User
{
    return User::factory()->admin()->create(array_merge([
        'login_id' => 'ADMIN001',
        'password' => 'Secret123!',
        'is_active' => true,
    ], $overrides));
}

function createBpUser(array $overrides = []): array
{
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'Test BP');

    $user = User::factory()->bp($partner)->create(array_merge([
        'login_id' => 'BPUSER001',
        'password' => 'Secret123!',
        'is_active' => true,
    ], $overrides));

    return [$user, $partner];
}

function createCustomerUser(array $overrides = []): array
{
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'Test BP');
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $partner->id,
    ]);

    $user = User::factory()->customer($customer)->create(array_merge([
        'login_id' => 'CUSUSER001',
        'password' => 'Secret123!',
        'is_active' => true,
    ], $overrides));

    return [$user, $customer];
}

it('shows three separated login pages', function () {
    $this->get(route('admin.login'))->assertOk()->assertSee('管理者ログイン');
    $this->get(route('bp.login'))->assertOk()->assertSee('BPログイン');
    $this->get(route('customer.login'))->assertOk()->assertSee('カスタマーログイン');
});

it('allows admin to login only via admin login', function () {
    createAdminUser();

    $this->post(route('admin.login.store'), [
        'login_id' => 'admin001',
        'password' => 'Secret123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated('admin');
});

it('rejects bp credentials on admin login', function () {
    createBpUser();

    $this->from(route('admin.login'))
        ->post(route('admin.login.store'), [
            'login_id' => 'BPUSER001',
            'password' => 'Secret123!',
        ])
        ->assertRedirect(route('admin.login'))
        ->assertSessionHasErrors('login_id');

    $this->assertGuest('admin');
});

it('allows bp to login with login_id, bpn and password', function () {
    [, $partner] = createBpUser();

    $this->post(route('bp.login.store'), [
        'login_id' => 'bpuser001',
        'bpn' => strtolower($partner->code),
        'password' => 'Secret123!',
    ])->assertRedirect(route('bp.dashboard'));

    $this->assertAuthenticated('bp');
});

it('rejects bp login when bpn does not match', function () {
    createBpUser();

    $this->from(route('bp.login'))
        ->post(route('bp.login.store'), [
            'login_id' => 'BPUSER001',
            'bpn' => 'BPN209999999',
            'password' => 'Secret123!',
        ])
        ->assertRedirect(route('bp.login'))
        ->assertSessionHasErrors('login_id');

    $this->assertGuest('bp');
});

it('allows customer to login with login_id, cn and password', function () {
    [, $customer] = createCustomerUser();

    $this->post(route('customer.login.store'), [
        'login_id' => 'cususer001',
        'cn' => strtolower($customer->code),
        'password' => 'Secret123!',
    ])->assertRedirect(route('customer.dashboard'));

    $this->assertAuthenticated('customer');
});

it('rejects inactive users', function () {
    createAdminUser(['is_active' => false]);

    $this->from(route('admin.login'))
        ->post(route('admin.login.store'), [
            'login_id' => 'ADMIN001',
            'password' => 'Secret123!',
        ])
        ->assertRedirect(route('admin.login'))
        ->assertSessionHasErrors('login_id');

    $this->assertGuest('admin');
});

it('prevents cross-area access after login', function () {
    createAdminUser();

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN001',
        'password' => 'Secret123!',
    ]);

    $this->get(route('admin.dashboard'))->assertOk();
    $this->get(route('bp.dashboard'))->assertRedirect(route('bp.login'));
    $this->get(route('customer.dashboard'))->assertRedirect(route('customer.login'));
});

it('logs out from the current area only', function () {
    createAdminUser();

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMIN001',
        'password' => 'Secret123!',
    ]);

    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    $this->assertGuest('admin');
});
