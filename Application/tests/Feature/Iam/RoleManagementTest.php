<?php

use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Iam\Services\RoleManagementService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function roleMgmtAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'ROLEMGMT',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('creates custom role and exposes it in assignable roles', function () {
    $admin = roleMgmtAdmin();
    $service = app(RoleManagementService::class);

    $role = $service->create($admin, [
        'code' => 'bp_custom_ops',
        'name' => 'BPカスタム運用',
        'scope' => RoleScope::Bp->value,
        'description' => 'テスト用',
    ], ['contract.view', 'inquiry.view']);

    expect($role->code)->toBe('bp_custom_ops')
        ->and($role->permissions)->toHaveCount(2)
        ->and(AuditLog::query()->where('action', 'role.create')->exists())->toBeTrue();

    $codes = app(\App\Domains\Iam\Services\UserManagementService::class)
        ->assignableRoleCodes($admin, \App\Domains\Auth\Enums\UserType::Bp);

    expect($codes)->toContain('bp_custom_ops')
        ->and($codes)->toContain('bp_sales');
});

it('shows bp-scoped custom role on bp user create page', function () {
    $admin = roleMgmtAdmin();
    app(RoleManagementService::class)->create($admin, [
        'code' => 'bp_field',
        'name' => 'BP現場',
        'scope' => RoleScope::Bp->value,
        'description' => null,
    ], ['contract.view']);

    $bpn = app(\App\Domains\Auth\Services\NumberSequenceService::class)
        ->next(\App\Domains\Auth\Enums\PartnerCodePrefix::Bpn);
    $bp = app(\App\Domains\Auth\Services\BpHierarchyService::class)->createRoot($bpn, 'Field BP');
    $owner = User::factory()->bp($bp)->create([
        'login_id' => 'BPROLEOWNER',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($owner, 'bp_owner', RoleScope::Bp, $bp->id);

    $this->post(route('bp.login.store'), [
        'login_id' => 'BPROLEOWNER',
        'bpn' => $bp->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.users.create', ['type' => 'bp']))
        ->assertOk()
        ->assertSee('BP現場')
        ->assertSee('bp_field');
});

it('does not show system-scoped custom role on bp user create page', function () {
    $admin = roleMgmtAdmin();
    app(RoleManagementService::class)->create($admin, [
        'code' => 'sys_ops',
        'name' => 'システム運用',
        'scope' => RoleScope::System->value,
        'description' => null,
    ], ['audit.log.view']);

    $bpn = app(\App\Domains\Auth\Services\NumberSequenceService::class)
        ->next(\App\Domains\Auth\Enums\PartnerCodePrefix::Bpn);
    $bp = app(\App\Domains\Auth\Services\BpHierarchyService::class)->createRoot($bpn, 'Sys Scope BP');
    $owner = User::factory()->bp($bp)->create([
        'login_id' => 'BPSYSCOPE',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($owner, 'bp_owner', RoleScope::Bp, $bp->id);

    $this->post(route('bp.login.store'), [
        'login_id' => 'BPSYSCOPE',
        'bpn' => $bp->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.users.create', ['type' => 'bp']))
        ->assertOk()
        ->assertDontSee('sys_ops')
        ->assertDontSee('システム運用');
});

it('updates builtin role permissions but rejects deletion', function () {
    $admin = roleMgmtAdmin();
    $service = app(RoleManagementService::class);
    $role = Role::query()->where('code', 'bp_support')->firstOrFail();

    $service->update($admin, $role, [
        'name' => 'BPサポート（改）',
        'description' => '更新',
    ], ['inquiry.view', 'inquiry.reply']);

    expect($role->fresh()->name)->toBe('BPサポート（改）')
        ->and($role->fresh()->permissions->pluck('code')->sort()->values()->all())
        ->toBe(['inquiry.reply', 'inquiry.view']);

    expect(fn () => $service->delete($admin, $role->fresh()))
        ->toThrow(InvalidArgumentException::class, '組み込みロールは削除できません。');
});

it('manages roles via admin http ui', function () {
    $admin = roleMgmtAdmin();

    $this->post(route('admin.login.store'), [
        'login_id' => 'ROLEMGMT',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.roles.index'))
        ->assertOk()
        ->assertSee('ロール管理')
        ->assertSee('system_admin');

    $this->get(route('admin.roles.create'))
        ->assertOk()
        ->assertSee('ロール作成');

    $this->post(route('admin.roles.store'), [
        'code' => 'customer_readonly',
        'name' => '参照のみ',
        'scope' => 'customer',
        'description' => '閲覧専用',
        'permission_codes' => ['contract.view', 'invoice.view'],
    ])->assertRedirect();

    $role = Role::query()->where('code', 'customer_readonly')->first();
    expect($role)->not->toBeNull();

    $this->get(route('admin.roles.edit', $role))
        ->assertOk()
        ->assertSee('参照のみ');

    $this->put(route('admin.roles.update', $role), [
        'name' => '参照のみ（更新）',
        'scope' => 'customer',
        'description' => '更新後',
        'permission_codes' => ['contract.view'],
    ])->assertRedirect(route('admin.roles.edit', $role));

    expect($role->fresh()->name)->toBe('参照のみ（更新）')
        ->and($role->fresh()->permissions)->toHaveCount(1);

    $this->delete(route('admin.roles.destroy', $role))
        ->assertRedirect(route('admin.roles.index'));

    expect(Role::query()->where('code', 'customer_readonly')->exists())->toBeFalse();
});

it('keeps system_admin permissions as all permissions on update', function () {
    $admin = roleMgmtAdmin();
    $role = Role::query()->where('code', 'system_admin')->firstOrFail();
    $allCount = Permission::query()->count();

    app(RoleManagementService::class)->update($admin, $role, [
        'name' => 'システム管理者',
        'description' => '全権限',
    ], ['audit.log.view']);

    expect($role->fresh()->permissions)->toHaveCount($allCount);
});
