<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
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

it('sends guests from admin and bp roots to their login screens', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->get('/bp')->assertRedirect(route('bp.login'));

    $this->get(route('admin.login'))->assertOk()->assertSee('管理者ログイン');
    $this->get(route('bp.login'))->assertOk()->assertSee('BPログイン');
});

it('sends authenticated users from admin and bp roots to dashboards', function () {
    $admin = User::factory()->admin()->create([
        'login_id' => 'ROOTADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $this->post(route('admin.login.store'), [
        'login_id' => 'ROOTADMIN',
        'password' => 'Password123!',
    ]);

    $this->get('/admin')->assertRedirect(route('admin.dashboard'));
    $this->get(route('admin.dashboard'))->assertOk();

    $this->post(route('admin.logout'));

    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $partner = app(BpHierarchyService::class)->createRoot($bpn, 'Root BP');
    $bpUser = User::factory()->bp($partner)->create([
        'login_id' => 'ROOTBP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $partner->id);

    $this->post(route('bp.login.store'), [
        'login_id' => 'ROOTBP',
        'bpn' => $partner->code,
        'password' => 'Password123!',
    ]);

    $this->get('/bp')->assertRedirect(route('bp.dashboard'));
});
