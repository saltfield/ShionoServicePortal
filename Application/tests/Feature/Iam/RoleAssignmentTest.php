<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Iam\Services\UserManagementService;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

it('assigns multiple roles and unions permissions', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'ROLEADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $bp = app(BpHierarchyService::class)->createRoot($bpn, 'Role BP');

    $service = app(UserManagementService::class);
    $user = $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => 'ROLEBPUSER',
        'name' => 'Role BP User',
        'password' => 'Password123!',
        'bp_id' => $bp->id,
        'role_code' => 'bp_sales',
        'is_active' => true,
    ]);

    expect($user->fresh()->roles)->toHaveCount(1)
        ->and(app(RbacService::class)->hasPermission($user->fresh(), 'contract.create'))->toBeTrue()
        ->and(app(RbacService::class)->hasPermission($user->fresh(), 'inquiry.reply'))->toBeFalse();

    $service->assignRoleToUser($admin, $user->fresh(), 'bp_support');

    $user->refresh()->load('roles');
    expect($user->roles->pluck('code')->sort()->values()->all())->toBe(['bp_sales', 'bp_support'])
        ->and(app(RbacService::class)->hasPermission($user, 'contract.create'))->toBeTrue()
        ->and(app(RbacService::class)->hasPermission($user, 'inquiry.reply'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'role.assign')->exists())->toBeTrue();
});

it('rejects revoking the last role and allows revoking extras', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'ROLEADMIN2',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $bp = app(BpHierarchyService::class)->createRoot($bpn, 'Role BP 2');

    $service = app(UserManagementService::class);
    $user = $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => 'ROLEBPUSER2',
        'name' => 'Role BP User 2',
        'password' => 'Password123!',
        'bp_id' => $bp->id,
        'role_code' => 'bp_sales',
        'is_active' => true,
    ]);

    expect(fn () => $service->revokeRoleFromUser($admin, $user->fresh(), 'bp_sales'))
        ->toThrow(InvalidArgumentException::class, '最後のロールは解除できません。');

    $service->assignRoleToUser($admin, $user->fresh(), 'bp_owner');
    $service->revokeRoleFromUser($admin, $user->fresh(), 'bp_sales');

    $user->refresh()->load('roles');
    expect($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->code)->toBe('bp_owner')
        ->and(AuditLog::query()->where('action', 'role.revoke')->exists())->toBeTrue();
});

it('assigns role via admin http endpoint', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'ROLEHTTP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $bp = app(BpHierarchyService::class)->createRoot($bpn, 'HTTP Role BP');

    $target = User::factory()->bp($bp)->create([
        'login_id' => 'HTTPTARGET',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($target, 'bp_sales', RoleScope::Bp, $bp->id);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ROLEHTTP',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.users.roles.assign', $target), [
        'role_code' => 'bp_support',
    ])->assertRedirect();

    expect($target->fresh()->roles->pluck('code')->sort()->values()->all())
        ->toBe(['bp_sales', 'bp_support']);

    $this->get(route('admin.users.edit', $target))
        ->assertOk()
        ->assertSee('ロールの割り当て')
        ->assertSee('bp_support');
});
