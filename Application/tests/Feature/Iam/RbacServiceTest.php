<?php

use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Models\User;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
});

it('denies permission when user has no roles', function () {
    $user = User::factory()->admin()->create();

    expect(app(RbacService::class)->hasPermission($user, 'audit.log.view'))->toBeFalse()
        ->and(app(AuthorizationService::class)->can($user, 'audit.log.view'))->toBeFalse();
});

it('grants all permissions to system_admin role', function () {
    $user = User::factory()->admin()->create();
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $auth = app(AuthorizationService::class);

    expect($auth->can($user, 'audit.log.view'))->toBeTrue()
        ->and($auth->can($user, 'iam.policy.manage'))->toBeTrue()
        ->and($auth->can($user, 'admin.user.force_password'))->toBeTrue();
});

it('grants only sales permissions to bp_sales role', function () {
    $user = User::factory()->bp()->create();
    app(RbacService::class)->assignRole(
        $user,
        'bp_sales',
        RoleScope::Bp,
        $user->bp_id
    );

    $auth = app(AuthorizationService::class);

    expect($auth->can($user, 'contract.create'))->toBeTrue()
        ->and($auth->can($user, 'contract.view'))->toBeTrue()
        ->and($auth->can($user, 'iam.user.manage'))->toBeFalse()
        ->and($auth->can($user, 'audit.log.view'))->toBeFalse();
});

it('revokes a role and removes its permissions', function () {
    $user = User::factory()->admin()->create();
    $rbac = app(RbacService::class);
    $rbac->assignRole($user, 'system_admin', RoleScope::System);

    expect(app(AuthorizationService::class)->can($user, 'audit.log.view'))->toBeTrue();

    $rbac->revokeRole($user, 'system_admin', RoleScope::System);

    expect(app(AuthorizationService::class)->can($user, 'audit.log.view'))->toBeFalse();
});

it('blocks admin route without permission via middleware', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'ADMINRBAC',
        'password' => 'Password123!',
    ]);

    // temporary protected route for test
    \Illuminate\Support\Facades\Route::middleware(['web', 'auth.guard:admin', 'permission:audit.log.view,admin'])
        ->get('/admin/audit-logs-test', fn () => 'ok')
        ->name('admin.audit-logs-test');

    $this->post(route('admin.login.store'), [
        'login_id' => 'ADMINRBAC',
        'password' => 'Password123!',
    ]);

    $this->get('/admin/audit-logs-test')->assertForbidden();

    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    $this->get('/admin/audit-logs-test')->assertOk()->assertSee('ok');
});
