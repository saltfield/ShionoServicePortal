<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\AuthorizationService;
use App\Domains\Iam\Services\RbacService;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function bpTree(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);

    $root = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Root');
    $child = $hierarchy->createChild($root, $seq->next(PartnerCodePrefix::Bpn), 'Child');
    $grand = $hierarchy->createChild($child, $seq->next(PartnerCodePrefix::Bpn), 'Grand');

    return compact('root', 'child', 'grand');
}

it('allows contract.view only for same bp or descendants', function () {
    $tree = bpTree();
    $user = User::factory()->bp($tree['root'])->create();
    app(RbacService::class)->assignRole($user, 'bp_owner', RoleScope::Bp, $tree['root']->id);

    $auth = app(AuthorizationService::class);

    expect($auth->can($user, 'contract.view', [
        'resource_type' => 'contract',
        'owner_bp_id' => $tree['root']->id,
    ]))->toBeTrue()
        ->and($auth->can($user, 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $tree['child']->id,
        ]))->toBeTrue()
        ->and($auth->can($user, 'contract.view', [
            'resource_type' => 'contract',
            'owner_bp_id' => $tree['grand']->id,
        ]))->toBeTrue();

    $outsiderRoot = app(BpHierarchyService::class)->createRoot(
        app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn),
        'Other'
    );

    expect($auth->can($user, 'contract.view', [
        'resource_type' => 'contract',
        'owner_bp_id' => $outsiderRoot->id,
    ]))->toBeFalse();
});

it('allows wholesale edit only for direct child bp', function () {
    $tree = bpTree();
    $user = User::factory()->bp($tree['root'])->create();
    app(RbacService::class)->assignRole($user, 'bp_owner', RoleScope::Bp, $tree['root']->id);

    $auth = app(AuthorizationService::class);

    expect($auth->can($user, 'price.wholesale.edit', [
        'resource_type' => 'price',
        'owner_bp_id' => $tree['child']->id,
    ]))->toBeTrue()
        ->and($auth->can($user, 'price.wholesale.edit', [
            'resource_type' => 'price',
            'owner_bp_id' => $tree['grand']->id,
        ]))->toBeFalse()
        ->and($auth->can($user, 'price.wholesale.edit', [
            'resource_type' => 'price',
            'owner_bp_id' => $tree['root']->id,
        ]))->toBeFalse();
});

it('denies high-amount approval by immediate parent only', function () {
    $tree = bpTree();
    $parentUser = User::factory()->bp($tree['child'])->create();
    $grandUser = User::factory()->bp($tree['root'])->create();

    $rbac = app(RbacService::class);
    $rbac->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $tree['child']->id);
    $rbac->assignRole($grandUser, 'bp_owner', RoleScope::Bp, $tree['root']->id);

    $auth = app(AuthorizationService::class);
    $resource = [
        'resource_type' => 'contract',
        'owner_bp_id' => $tree['grand']->id,
        'amount' => 1500000,
    ];

    expect($auth->can($parentUser, 'contract.approve', $resource))->toBeFalse()
        ->and($auth->can($grandUser, 'contract.approve', $resource))->toBeTrue();
});

it('denies reply to closed inquiry unless reopen permission exists', function () {
    $tree = bpTree();
    $user = User::factory()->bp($tree['root'])->create();
    app(RbacService::class)->assignRole($user, 'bp_support', RoleScope::Bp, $tree['root']->id);

    $role = $user->roles()->where('code', 'bp_support')->first();
    $reopen = Permission::query()->where('code', 'inquiry.reopen')->first();
    if ($reopen) {
        $role->permissions()->detach($reopen->id);
    }
    $user = $user->fresh(['roles']);

    $auth = app(AuthorizationService::class);

    expect($auth->can($user, 'inquiry.reply', [
        'resource_type' => 'inquiry',
        'owner_bp_id' => $tree['root']->id,
        'status' => 'closed',
    ]))->toBeFalse();

    $role->permissions()->attach($reopen->id);
    $user = $user->fresh(['roles']);

    expect($auth->can($user, 'inquiry.reply', [
        'resource_type' => 'inquiry',
        'owner_bp_id' => $tree['root']->id,
        'status' => 'closed',
    ]))->toBeTrue();
});

it('still requires rbac even when abac would allow', function () {
    $tree = bpTree();
    $user = User::factory()->bp($tree['root'])->create();
    // no roles

    expect(app(AuthorizationService::class)->can($user, 'contract.view', [
        'resource_type' => 'contract',
        'owner_bp_id' => $tree['root']->id,
    ]))->toBeFalse();
});
