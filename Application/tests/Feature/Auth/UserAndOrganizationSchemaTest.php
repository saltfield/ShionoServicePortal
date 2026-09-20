<?php

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates an admin user without bp or customer linkage', function () {
    $user = User::factory()->admin()->create([
        'login_id' => 'admin001',
    ]);

    expect($user->login_id)->toBe('ADMIN001')
        ->and($user->user_type)->toBe(UserType::Admin)
        ->and($user->bp_id)->toBeNull()
        ->and($user->customer_id)->toBeNull();
});

it('creates a bp user linked to a business partner', function () {
    $partner = app(BpHierarchyService::class)->createRoot(
        app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn),
        'Root BP'
    );

    $user = User::factory()->bp($partner)->create([
        'login_id' => 'bp.user',
    ]);

    expect($user->user_type)->toBe(UserType::Bp)
        ->and($user->bp_id)->toBe($partner->id)
        ->and($user->businessPartner->code)->toStartWith('BPN');
});

it('creates a customer user linked to a customer under a bp', function () {
    $partner = app(BpHierarchyService::class)->createRoot(
        app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn),
        'Root BP'
    );

    $customer = Customer::factory()->create([
        'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $partner->id,
    ]);

    $user = User::factory()->customer($customer)->create();

    expect($user->user_type)->toBe(UserType::Customer)
        ->and($user->customer_id)->toBe($customer->id)
        ->and($customer->managingBp->id)->toBe($partner->id);
});

it('defaults bp two_factor_mode to optional', function () {
    $partner = app(BpHierarchyService::class)->createRoot('BPN202609001', 'Root');

    expect($partner->two_factor_mode)->toBe(TwoFactorMode::Optional);
});

it('creates self closure row for a root bp', function () {
    $partner = app(BpHierarchyService::class)->createRoot('BPN202609001', 'Root');

    $row = DB::table('bp_closure')
        ->where('ancestor_id', $partner->id)
        ->where('descendant_id', $partner->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->depth_diff)->toBe(0);
});

it('creates child bp with ancestor closure rows and depth limit awareness', function () {
    $service = app(BpHierarchyService::class);
    $root = $service->createRoot('BPN202609001', 'Root');
    $child = $service->createChild($root, 'BPN202609002', 'Child');

    expect($child->parent_id)->toBe($root->id)
        ->and($child->depth)->toBe(2);

    $links = DB::table('bp_closure')
        ->where('descendant_id', $child->id)
        ->orderBy('depth_diff')
        ->get();

    expect($links)->toHaveCount(2)
        ->and($links[0]->ancestor_id)->toBe($child->id)
        ->and((int) $links[0]->depth_diff)->toBe(0)
        ->and($links[1]->ancestor_id)->toBe($root->id)
        ->and((int) $links[1]->depth_diff)->toBe(1);
});

it('rejects creating a sixth hierarchy level', function () {
    $service = app(BpHierarchyService::class);
    $current = $service->createRoot('BPN202609001', 'L1');

    foreach (range(2, 5) as $level) {
        $current = $service->createChild($current, sprintf('BPN202609%03d', $level), "L{$level}");
    }

    expect($current->depth)->toBe(5);

    $service->createChild($current, 'BPN202609006', 'L6');
})->throws(InvalidArgumentException::class);
