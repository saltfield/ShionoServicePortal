<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Iam\Services\UserManagementService;
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

function userMgmtAdmin(array $overrides = []): User
{
    $user = User::factory()->admin()->create(array_merge([
        'login_id' => 'UMADMIN',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ], $overrides));

    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

function userMgmtBpOwner(): array
{
    $bpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $root = app(BpHierarchyService::class)->createRoot($bpn, 'UM Root BP');
    $childBpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $child = app(BpHierarchyService::class)->createChild($root, $childBpn, 'UM Child BP');

    $owner = User::factory()->bp($root)->create([
        'login_id' => 'UMBPOWNER',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($owner, 'bp_owner', RoleScope::Bp, $root->id);

    return compact('root', 'child', 'owner');
}

it('allows admin to create bp and customer users', function () {
    $admin = userMgmtAdmin();
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'UM Customer',
    ]);

    $service = app(UserManagementService::class);

    $bpUser = $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => 'NEWBPUSER',
        'name' => 'New BP',
        'email' => 'bp@example.com',
        'password' => 'Password123!',
        'bp_id' => $tree['child']->id,
        'role_code' => 'bp_sales',
        'is_active' => true,
        'must_change_password' => true,
    ]);

    $customerUser = $service->create($admin, [
        'user_type' => UserType::Customer->value,
        'login_id' => 'NEWCUSUSER',
        'name' => 'New Customer User',
        'password' => 'Password123!',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
    ]);

    expect($bpUser->user_type)->toBe(UserType::Bp)
        ->and($bpUser->bp_id)->toBe($tree['child']->id)
        ->and($bpUser->roles->first()?->code)->toBe('bp_sales')
        ->and($customerUser->customer_id)->toBe($customer->id)
        ->and(AuditLog::query()->where('action', 'user.create')->count())->toBe(2);
});

it('allows the same login_id across organizations but not within one', function () {
    $admin = userMgmtAdmin();
    $tree = userMgmtBpOwner();
    $outsideBpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $outside = app(BpHierarchyService::class)->createRoot($outsideBpn, 'Outside Shared BP');

    $cnA = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customerA = Customer::factory()->create([
        'code' => $cnA,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Customer A',
    ]);
    $cnB = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customerB = Customer::factory()->create([
        'code' => $cnB,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Customer B',
    ]);

    $service = app(UserManagementService::class);
    $shared = 'SHAREDID01';

    $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => $shared,
        'name' => 'BP Shared Root',
        'password' => 'Password123!',
        'bp_id' => $tree['root']->id,
        'role_code' => 'bp_sales',
        'is_active' => true,
    ]);
    $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => $shared,
        'name' => 'BP Shared Outside',
        'password' => 'Password123!',
        'bp_id' => $outside->id,
        'role_code' => 'bp_sales',
        'is_active' => true,
    ]);

    $service->create($admin, [
        'user_type' => UserType::Customer->value,
        'login_id' => $shared,
        'name' => 'Customer Shared A',
        'password' => 'Password123!',
        'customer_id' => $customerA->id,
        'role_code' => 'customer_owner',
        'is_active' => true,
    ]);
    $service->create($admin, [
        'user_type' => UserType::Customer->value,
        'login_id' => $shared,
        'name' => 'Customer Shared B',
        'password' => 'Password123!',
        'customer_id' => $customerB->id,
        'role_code' => 'customer_owner',
        'is_active' => true,
    ]);

    expect(User::query()->where('login_id', $shared)->count())->toBe(4);

    expect(fn () => $service->create($admin, [
        'user_type' => UserType::Customer->value,
        'login_id' => $shared,
        'name' => 'Duplicate Customer A',
        'password' => 'Password123!',
        'customer_id' => $customerA->id,
        'role_code' => 'customer_member',
        'is_active' => true,
    ]))->toThrow(\InvalidArgumentException::class, 'このログインIDは当該組織で既に使用されています。');

    expect(fn () => $service->create($admin, [
        'user_type' => UserType::Bp->value,
        'login_id' => $shared,
        'name' => 'Duplicate BP Root',
        'password' => 'Password123!',
        'bp_id' => $tree['root']->id,
        'role_code' => 'bp_support',
        'is_active' => true,
    ]))->toThrow(\InvalidArgumentException::class, 'このログインIDは当該組織で既に使用されています。');
});

it('allows bp owner to create users only within descendants', function () {
    $tree = userMgmtBpOwner();
    $outsideBpn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
    $outside = app(BpHierarchyService::class)->createRoot($outsideBpn, 'Outside BP');
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['child']->id,
        'name' => 'Child Customer',
    ]);

    $service = app(UserManagementService::class);

    $created = $service->create($tree['owner'], [
        'user_type' => UserType::Customer->value,
        'login_id' => 'BPCREATED',
        'name' => 'Created by BP',
        'password' => 'Password123!',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
    ]);

    expect($created->login_id)->toBe('BPCREATED');

    expect(fn () => $service->create($tree['owner'], [
        'user_type' => UserType::Bp->value,
        'login_id' => 'OUTSIDEBP',
        'name' => 'Outside',
        'password' => 'Password123!',
        'bp_id' => $outside->id,
        'role_code' => 'bp_sales',
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects customer member without manage permission', function () {
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Actor Customer',
    ]);
    $customerUser = User::factory()->customer($customer)->create(['login_id' => 'CUSACTOR']);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $customer->id);

    expect(fn () => app(UserManagementService::class)->create($customerUser, [
        'user_type' => UserType::Customer->value,
        'login_id' => 'SHOULDFAIL',
        'name' => 'Fail',
        'password' => 'Password123!',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('allows customer owner to create users only within own cn', function () {
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Owner Customer',
    ]);
    $other = Customer::factory()->create([
        'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Other Customer',
    ]);

    $owner = User::factory()->customer($customer)->create([
        'login_id' => 'CUSOWNER',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($owner, 'customer_owner', RoleScope::Customer, $customer->id);

    $service = app(UserManagementService::class);

    $created = $service->create($owner, [
        'user_type' => UserType::Customer->value,
        'login_id' => 'CUSMEMBER1',
        'name' => 'Member One',
        'password' => 'Password123!',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
    ]);

    expect($created->customer_id)->toBe($customer->id)
        ->and($created->roles->first()?->code)->toBe('customer_member')
        ->and(AuditLog::query()->where('action', 'user.create')->where('actor_user_id', $owner->id)->exists())->toBeTrue();

    expect(fn () => $service->create($owner, [
        'user_type' => UserType::Customer->value,
        'login_id' => 'OTHERCN',
        'name' => 'Other',
        'password' => 'Password123!',
        'customer_id' => $other->id,
        'role_code' => 'customer_member',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->create($owner, [
        'user_type' => UserType::Bp->value,
        'login_id' => 'TRYBP',
        'name' => 'BP',
        'password' => 'Password123!',
        'bp_id' => $tree['root']->id,
        'role_code' => 'bp_sales',
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->delete($owner, $owner))->toThrow(InvalidArgumentException::class);
});

it('allows customer owner http user management', function () {
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'HTTP Customer Org',
    ]);
    $owner = User::factory()->customer($customer)->create([
        'login_id' => 'CUSHTTPOWNER',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($owner, 'customer_owner', RoleScope::Customer, $customer->id);

    $this->post(route('customer.login.store'), [
        'login_id' => 'CUSHTTPOWNER',
        'cn' => $customer->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('customer.dashboard'));

    $this->get(route('customer.users.index'))->assertOk()->assertSee('ユーザー管理');

    $this->post(route('customer.users.store'), [
        'login_id' => 'CUSHTTPMEM',
        'name' => 'HTTP Member',
        'role_code' => 'customer_member',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => '1',
        'must_change_password' => '1',
    ])->assertRedirect(route('customer.users.index'));

    $created = User::query()->where('login_id', 'CUSHTTPMEM')->first();
    expect($created)->not->toBeNull()
        ->and($created->customer_id)->toBe($customer->id);

    $this->get(route('customer.users.edit', $created))->assertOk()->assertSee('ユーザー編集');
});

it('updates and soft-deletes users with audit logs', function () {
    $admin = userMgmtAdmin(['login_id' => 'UMADMIN2']);
    $target = User::factory()->admin()->create(['login_id' => 'UMTARGET', 'name' => 'Before']);
    app(RbacService::class)->assignRole($target, 'system_admin', RoleScope::System);

    $service = app(UserManagementService::class);
    $service->update($admin, $target, [
        'name' => 'After',
        'email' => 'after@example.com',
        'role_code' => 'system_admin',
        'is_active' => false,
        'must_change_password' => true,
    ]);

    expect($target->fresh()->name)->toBe('After')
        ->and($target->fresh()->is_active)->toBeFalse()
        ->and($target->fresh()->must_change_password)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.update')->exists())->toBeTrue();

    $service->delete($admin, $target);

    expect(User::withTrashed()->find($target->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.delete')->exists())->toBeTrue();
});

it('allows admin http create and edit flows', function () {
    $admin = userMgmtAdmin(['login_id' => 'HTTPUMADMIN']);
    $tree = userMgmtBpOwner();

    $this->post(route('admin.login.store'), [
        'login_id' => 'HTTPUMADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.users.create', ['type' => 'bp']))
        ->assertOk()
        ->assertSee('ユーザー作成');

    $this->post(route('admin.users.store'), [
        'user_type' => 'bp',
        'login_id' => 'HTTPBPNEW',
        'name' => 'HTTP BP',
        'email' => 'httpbp@example.com',
        'bp_id' => $tree['root']->id,
        'role_code' => 'bp_support',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => '1',
        'must_change_password' => '1',
    ])->assertRedirect(route('admin.users.index', ['tab' => 'bp']));

    $created = User::query()->where('login_id', 'HTTPBPNEW')->first();
    expect($created)->not->toBeNull();

    $this->get(route('admin.users.edit', $created))
        ->assertOk()
        ->assertSee('ユーザー編集');

    $this->put(route('admin.users.update', $created), [
        'name' => 'HTTP BP Updated',
        'email' => 'updated@example.com',
        'role_code' => 'bp_sales',
        'is_active' => '1',
    ])->assertRedirect(route('admin.users.index', ['tab' => 'bp']));

    expect($created->fresh()->name)->toBe('HTTP BP Updated')
        ->and($created->fresh()->roles->first()?->code)->toBe('bp_sales');
});

it('allows bp owner http user management within scope', function () {
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Scoped Customer',
    ]);

    $this->post(route('bp.login.store'), [
        'login_id' => 'UMBPOWNER',
        'bpn' => $tree['root']->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('bp.dashboard'));

    $this->get(route('bp.users.index'))->assertOk()->assertSee('ユーザー管理');

    $this->post(route('bp.users.store'), [
        'user_type' => 'customer',
        'login_id' => 'BPHTTPCUS',
        'name' => 'BP HTTP Customer',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => '1',
        'must_change_password' => '1',
    ])->assertRedirect(route('bp.customers.show', ['customer' => $customer, 'tab' => 'users']));

    expect(User::query()->where('login_id', 'BPHTTPCUS')->exists())->toBeTrue();
});

it('limits bp header user management to own bp and manages child users on bp detail', function () {
    $tree = userMgmtBpOwner();
    $childUser = User::factory()->bp($tree['child'])->create([
        'login_id' => 'CHILDONLY',
        'name' => 'Child Only User',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($childUser, 'bp_sales', RoleScope::Bp, $tree['child']->id);

    $this->post(route('bp.login.store'), [
        'login_id' => 'UMBPOWNER',
        'bpn' => $tree['root']->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.users.index'))
        ->assertOk()
        ->assertSee('自BP')
        ->assertSee('UMBPOWNER')
        ->assertDontSee('CHILDONLY');

    $this->get(route('bp.business-partners.show', ['businessPartner' => $tree['child'], 'tab' => 'users']))
        ->assertOk()
        ->assertSee('ユーザー管理')
        ->assertSee('CHILDONLY')
        ->assertDontSee('UMBPOWNER');
});

it('lists customer users on admin and bp customer detail and returns after create', function () {
    $admin = userMgmtAdmin(['login_id' => 'CUSDETAILADMIN']);
    $tree = userMgmtBpOwner();
    $cn = app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn);
    $customer = Customer::factory()->create([
        'code' => $cn,
        'managing_bp_id' => $tree['root']->id,
        'name' => 'Detail Customer',
    ]);
    $existing = User::factory()->customer($customer)->create([
        'login_id' => 'EXISTCUS',
        'name' => 'Existing Customer User',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($existing, 'customer_member', RoleScope::Customer, $customer->id);

    $this->post(route('admin.login.store'), [
        'login_id' => 'CUSDETAILADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.customers.show', ['customer' => $customer, 'tab' => 'users']))
        ->assertOk()
        ->assertSee('ユーザー追加')
        ->assertSee('EXISTCUS');

    $this->get(route('admin.customers.show', $customer))
        ->assertOk()
        ->assertSee('基本情報')
        ->assertSee('拠点')
        ->assertSee('価格')
        ->assertSee('ユーザー管理')
        ->assertSee('契約');

    $this->get(route('admin.users.index', ['tab' => 'customer']))
        ->assertOk()
        ->assertSee('EXISTCUS');

    $this->post(route('admin.users.store'), [
        'user_type' => 'customer',
        'login_id' => 'ADMINRETURNCUS',
        'name' => 'Admin Return Customer',
        'customer_id' => $customer->id,
        'role_code' => 'customer_owner',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => '1',
        'must_change_password' => '1',
        'return_customer_id' => $customer->id,
    ])->assertRedirect(route('admin.customers.show', ['customer' => $customer, 'tab' => 'users']));

    expect(User::query()->where('login_id', 'ADMINRETURNCUS')->exists())->toBeTrue();

    $this->post(route('admin.logout'));

    $this->post(route('bp.login.store'), [
        'login_id' => 'UMBPOWNER',
        'bpn' => $tree['root']->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('bp.dashboard'));

    $this->get(route('bp.customers.show', ['customer' => $customer, 'tab' => 'users']))
        ->assertOk()
        ->assertSee('EXISTCUS')
        ->assertSee('ADMINRETURNCUS');

    $this->post(route('bp.users.store'), [
        'user_type' => 'customer',
        'login_id' => 'BPRETURNCUS',
        'name' => 'BP Return Customer',
        'customer_id' => $customer->id,
        'role_code' => 'customer_member',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'is_active' => '1',
        'must_change_password' => '1',
        'return_customer_id' => $customer->id,
    ])->assertRedirect(route('bp.customers.show', ['customer' => $customer, 'tab' => 'users']));

    expect(User::query()->where('login_id', 'BPRETURNCUS')->exists())->toBeTrue();
});
