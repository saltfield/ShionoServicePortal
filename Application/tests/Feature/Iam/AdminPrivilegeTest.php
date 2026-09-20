<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\AdminPrivilegeService;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function privilegeAdmin(array $overrides = []): User
{
    $user = User::factory()->admin()->create(array_merge([
        'login_id' => 'PRIVADMIN',
        'password' => 'Password123!',
        'is_active' => true,
    ], $overrides));

    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('forces password change and writes audit log', function () {
    $admin = privilegeAdmin();
    $target = User::factory()->admin()->create(['login_id' => 'TARGETPW']);

    app(AdminPrivilegeService::class)->forcePasswordChange($admin, $target);

    expect($target->fresh()->must_change_password)->toBeTrue();

    $log = AuditLog::query()->where('action', 'admin.user.force_password')->first();
    expect($log)->not->toBeNull()
        ->and($log->result)->toBe('success')
        ->and($log->actor_user_id)->toBe($admin->id)
        ->and($log->target_user_id)->toBe($target->id);
});

it('rejects forcing password change on self', function () {
    $admin = privilegeAdmin();

    expect(fn () => app(AdminPrivilegeService::class)->forcePasswordChange($admin, $admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('sets and clears two factor emergency skip with audit logs', function () {
    $admin = privilegeAdmin();
    $target = User::factory()->admin()->create(['login_id' => 'TARGET2FA']);

    $service = app(AdminPrivilegeService::class);
    $service->forceDisableTwoFactor($admin, $target);

    expect($target->fresh()->two_factor_forced_disabled)->toBeTrue();

    $service->clearForceDisableTwoFactor($admin, $target);

    expect($target->fresh()->two_factor_forced_disabled)->toBeFalse();

    expect(AuditLog::query()->where('action', 'admin.user.reset_2fa')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'admin.user.clear_reset_2fa')->count())->toBe(1);
});

it('updates bp two factor mode and writes audit log', function () {
    $admin = privilegeAdmin();
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'Privilege BP');

    app(AdminPrivilegeService::class)->updateBpTwoFactorMode($admin, $partner, TwoFactorMode::Forced);

    expect($partner->fresh()->two_factor_mode)->toBe(TwoFactorMode::Forced);

    $log = AuditLog::query()->where('action', 'admin.bp.two_factor.manage')->first();
    expect($log)->not->toBeNull()
        ->and($log->meta['after'])->toBe('forced')
        ->and($log->meta['bp_code'])->toBe($partner->code);
});

it('updates customer two factor mode via admin screen', function () {
    $admin = privilegeAdmin(['login_id' => 'TFADMIN']);
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'TF BP');
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $partner->id,
        'name' => 'TF Customer',
        'two_factor_mode' => TwoFactorMode::Optional,
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'TFADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.bp-two-factor.index', ['tab' => 'customer']))
        ->assertOk()
        ->assertDontSee($cn);

    $this->get(route('admin.bp-two-factor.index', [
        'tab' => 'customer',
        'managing_bp_id' => $partner->id,
    ]))
        ->assertOk()
        ->assertSee($cn)
        ->assertSee('TF Customer');

    $this->put(route('admin.bp-two-factor.customers.update', $customer), [
        'two_factor_mode' => TwoFactorMode::Forced->value,
    ])->assertRedirect()->assertSessionHas('status');

    expect($customer->fresh()->two_factor_mode)->toBe(TwoFactorMode::Forced);
    expect(AuditLog::query()->where('action', 'admin.customer.two_factor.manage')->exists())->toBeTrue();
});

it('allows admin with permission to manage privileges via http', function () {
    $admin = privilegeAdmin(['login_id' => 'HTTPADMIN']);
    $target = User::factory()->admin()->create(['login_id' => 'HTTPTARGET']);

    $this->post(route('admin.login.store'), [
        'login_id' => 'HTTPADMIN',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->get(route('admin.users.index'))->assertOk()->assertSee('HTTPTARGET');

    $this->get(route('admin.users.index', ['tab' => 'admin']))
        ->assertOk()
        ->assertSee('管理者')
        ->assertSee('HTTPTARGET');

    $this->post(route('admin.users.force-password', $target))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($target->fresh()->must_change_password)->toBeTrue();
});

it('filters privilege users by bpn and bp name', function () {
    $admin = privilegeAdmin(['login_id' => 'SEARCHADMIN']);

    $bpnA = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partnerA = app(BpHierarchyService::class)->createRoot($bpnA, 'Alpha Partner');
    $bpUser = User::factory()->bp($partnerA)->create(['login_id' => 'BPSEARCHA']);

    $bpnB = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partnerB = app(BpHierarchyService::class)->createRoot($bpnB, 'Beta Partner');
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $partnerB->id,
        'name' => 'Customer Under Beta',
    ]);
    $customerUser = User::factory()->customer($customer)->create(['login_id' => 'CUSSEARCHB']);

    User::factory()->admin()->create(['login_id' => 'ADMINNOBP']);

    $this->post(route('admin.login.store'), [
        'login_id' => 'SEARCHADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.users.index', ['tab' => 'bp']))
        ->assertOk()
        ->assertSee('BPSEARCHA')
        ->assertDontSee('CUSSEARCHB')
        ->assertDontSee('ADMINNOBP');

    $this->get(route('admin.users.index', ['tab' => 'customer']))
        ->assertOk()
        ->assertDontSee('CUSSEARCHB')
        ->assertSee('管理BPを選択すると');

    $this->get(route('admin.users.index', ['tab' => 'bp', 'bpn' => $partnerA->code]))
        ->assertOk()
        ->assertSee('BPSEARCHA');

    $this->get(route('admin.users.index', [
        'tab' => 'customer',
        'managing_bp_id' => $partnerB->id,
    ]))
        ->assertOk()
        ->assertSee('CUSSEARCHB')
        ->assertSee('カスタマー名')
        ->assertSee('管理BP名');

    $this->get(route('admin.users.index', [
        'tab' => 'customer',
        'managing_bp_id' => $partnerB->id,
        'cn' => $customer->code,
    ]))
        ->assertOk()
        ->assertSee('CUSSEARCHB');

    $this->get(route('admin.users.index', [
        'tab' => 'customer',
        'managing_bp_id' => $partnerB->id,
        'cn_name' => 'Under Beta',
    ]))
        ->assertOk()
        ->assertSee('CUSSEARCHB');

    $this->get(route('admin.users.index', [
        'tab' => 'customer',
        'managing_bp_id' => $partnerA->id,
    ]))
        ->assertOk()
        ->assertDontSee('CUSSEARCHB');

    expect($bpUser->businessPartner->code)->toBe($partnerA->code)
        ->and($customerUser->customer->managing_bp_id)->toBe($partnerB->id);
});

it('forbids privilege pages without permission', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'NOPERM',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.login.store'), [
        'login_id' => 'NOPERM',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.users.index'))->assertForbidden();
    $this->get(route('admin.bp-two-factor.index'))->assertForbidden();
    $this->get(route('admin.audit-logs.index'))->assertForbidden();
});

it('shows audit logs to permitted admin and logs successful login', function () {
    $admin = privilegeAdmin(['login_id' => 'AUDADMIN']);

    $this->post(route('admin.login.store'), [
        'login_id' => 'AUDADMIN',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    expect(AuditLog::query()->where('action', 'login.admin')->where('result', 'success')->exists())->toBeTrue();

    $this->get(route('admin.audit-logs.index'))
        ->assertOk()
        ->assertSee('login.admin');

    $log = AuditLog::query()->latest('id')->first();
    $this->get(route('admin.audit-logs.show', $log))->assertOk();
});

it('records failed login attempts in audit logs', function () {
    User::factory()->admin()->create([
        'login_id' => 'FAILADMIN',
        'password' => 'Password123!',
    ]);

    $this->from(route('admin.login'))
        ->post(route('admin.login.store'), [
            'login_id' => 'FAILADMIN',
            'password' => 'WrongPassword1!',
        ])
        ->assertRedirect(route('admin.login'));

    $log = AuditLog::query()->where('result', 'failure')->where('action', 'login.admin')->first();
    expect($log)->not->toBeNull()
        ->and($log->meta['reason'])->toBe('invalid_password');
});
