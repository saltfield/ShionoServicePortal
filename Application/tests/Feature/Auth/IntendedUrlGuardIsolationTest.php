<?php

use App\Domains\Auth\Support\GuardAwareRedirect;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

it('ignores stale bp intended url after admin login', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'INTENDEDADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $this->get(route('bp.dashboard'))
        ->assertRedirect(route('bp.login'));

    expect(session('url.intended'))->toBe(route('bp.dashboard'));

    $this->post(route('admin.login.store'), [
        'login_id' => 'INTENDEDADMIN',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticated('admin');
    expect(session()->has('url.intended'))->toBeFalse();
    $this->get(route('admin.dashboard'))->assertOk();
});

it('still follows same-guard intended url after login', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'SAMEGUARDADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $this->get(route('admin.users.index'))
        ->assertRedirect(route('admin.login'));

    $this->post(route('admin.login.store'), [
        'login_id' => 'SAMEGUARDADMIN',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.users.index'));
});

it('detects guard ownership of intended paths', function () {
    expect(GuardAwareRedirect::belongsToGuard('http://localhost/admin/dashboard', 'admin'))->toBeTrue()
        ->and(GuardAwareRedirect::belongsToGuard('/admin/users', 'admin'))->toBeTrue()
        ->and(GuardAwareRedirect::belongsToGuard('/bp/dashboard', 'admin'))->toBeFalse()
        ->and(GuardAwareRedirect::belongsToGuard('/bp', 'bp'))->toBeTrue()
        ->and(GuardAwareRedirect::belongsToGuard('/customer/invoices', 'customer'))->toBeTrue();
});
