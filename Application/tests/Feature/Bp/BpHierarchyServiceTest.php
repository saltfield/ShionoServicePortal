<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function hierarchy(): BpHierarchyService
{
    return app(BpHierarchyService::class);
}

function nextBpn(): string
{
    return app(NumberSequenceService::class)->next(PartnerCodePrefix::Bpn);
}

it('updates bp attributes without changing hierarchy', function () {
    $root = hierarchy()->createRoot(nextBpn(), 'Root');

    $updated = hierarchy()->update($root, [
        'name' => 'Renamed',
        'two_factor_mode' => TwoFactorMode::Forced,
        'is_active' => false,
    ]);

    expect($updated->name)->toBe('Renamed')
        ->and($updated->two_factor_mode)->toBe(TwoFactorMode::Forced)
        ->and($updated->is_active)->toBeFalse()
        ->and($updated->parent_id)->toBeNull()
        ->and($updated->depth)->toBe(1);
});

it('deletes a leaf bp and removes closure rows', function () {
    $root = hierarchy()->createRoot(nextBpn(), 'Root');
    $child = hierarchy()->createChild($root, nextBpn(), 'Child');

    hierarchy()->delete($child);

    expect(BusinessPartner::query()->whereKey($child->id)->exists())->toBeFalse()
        ->and(BusinessPartner::withTrashed()->whereKey($child->id)->exists())->toBeTrue()
        ->and(DB::table('bp_closure')->where('descendant_id', $child->id)->exists())->toBeFalse()
        ->and(DB::table('bp_closure')->where('ancestor_id', $root->id)->where('descendant_id', $root->id)->exists())->toBeTrue();
});

it('rejects deleting bp that has children', function () {
    $root = hierarchy()->createRoot(nextBpn(), 'Root');
    hierarchy()->createChild($root, nextBpn(), 'Child');

    hierarchy()->delete($root);
})->throws(InvalidArgumentException::class, '配下にBPが存在するため削除できません。');

it('rejects deleting bp that has customers', function () {
    $root = hierarchy()->createRoot(nextBpn(), 'Root');
    Customer::factory()->create([
        'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $root->id,
    ]);

    hierarchy()->delete($root);
})->throws(InvalidArgumentException::class, '配下にカスタマーが存在するため削除できません。');

it('rejects deleting bp that has users', function () {
    $root = hierarchy()->createRoot(nextBpn(), 'Root');
    User::factory()->bp($root)->create();

    hierarchy()->delete($root);
})->throws(InvalidArgumentException::class, '所属ユーザーが存在するため削除できません。');

it('moves a subtree under another parent and rebuilds closure', function () {
    $service = hierarchy();
    $root = $service->createRoot(nextBpn(), 'Root');
    $branchA = $service->createChild($root, nextBpn(), 'A');
    $branchB = $service->createChild($root, nextBpn(), 'B');
    $leaf = $service->createChild($branchA, nextBpn(), 'A1');

    $service->move($branchA, $branchB);

    $branchA->refresh();
    $leaf->refresh();

    expect($branchA->parent_id)->toBe($branchB->id)
        ->and($branchA->depth)->toBe(3)
        ->and($leaf->depth)->toBe(4);

    $leafAncestors = DB::table('bp_closure')
        ->where('descendant_id', $leaf->id)
        ->orderBy('depth_diff')
        ->get();

    expect($leafAncestors)->toHaveCount(4)
        ->and($leafAncestors[0]->ancestor_id)->toBe($leaf->id)
        ->and($leafAncestors[1]->ancestor_id)->toBe($branchA->id)
        ->and($leafAncestors[2]->ancestor_id)->toBe($branchB->id)
        ->and($leafAncestors[3]->ancestor_id)->toBe($root->id);
});

it('rejects moving under own descendant', function () {
    $service = hierarchy();
    $root = $service->createRoot(nextBpn(), 'Root');
    $child = $service->createChild($root, nextBpn(), 'Child');

    $service->move($root, $child);
})->throws(InvalidArgumentException::class, '配下のBPへは移動できません。');

it('rejects move that would exceed max depth', function () {
    $service = hierarchy();
    $root = $service->createRoot(nextBpn(), 'L1');
    $l2 = $service->createChild($root, nextBpn(), 'L2');
    $l3 = $service->createChild($l2, nextBpn(), 'L3');
    $l4 = $service->createChild($l3, nextBpn(), 'L4');
    $other = $service->createChild($root, nextBpn(), 'Other');
    $deepChild = $service->createChild($other, nextBpn(), 'OtherChild');

    // Move chain L2(with L3,L4) under deepChild(depth2) => L2 would be depth3, L4 depth5 OK
    // Move L2 under l4 would be deeper - use: move other+deepChild under l4
    // other depth2 + subtree height 2 = if parent l4 depth4 => other becomes 5, deepChild 6 -> reject
    $service->move($other, $l4);
})->throws(InvalidArgumentException::class, 'Business partner hierarchy cannot exceed 5 levels.');

it('lists descendants and ancestors via closure', function () {
    $service = hierarchy();
    $root = $service->createRoot(nextBpn(), 'Root');
    $child = $service->createChild($root, nextBpn(), 'Child');
    $grand = $service->createChild($child, nextBpn(), 'Grand');

    expect($service->descendants($root)->pluck('id')->all())->toBe([$child->id, $grand->id])
        ->and($service->ancestors($grand)->pluck('id')->all())->toBe([$child->id, $root->id])
        ->and($service->isSelfOrDescendant($root, $grand))->toBeTrue()
        ->and($service->isDescendant($grand, $root))->toBeFalse();
});

it('can move a subtree to become a new root', function () {
    $service = hierarchy();
    $root = $service->createRoot(nextBpn(), 'Root');
    $child = $service->createChild($root, nextBpn(), 'Child');
    $grand = $service->createChild($child, nextBpn(), 'Grand');

    $service->move($child, null);

    $child->refresh();
    $grand->refresh();

    expect($child->parent_id)->toBeNull()
        ->and($child->depth)->toBe(1)
        ->and($grand->depth)->toBe(2)
        ->and(DB::table('bp_closure')->where('descendant_id', $child->id)->count())->toBe(1)
        ->and(DB::table('bp_closure')->where('descendant_id', $grand->id)->count())->toBe(2);
});
