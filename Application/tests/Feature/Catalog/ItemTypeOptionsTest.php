<?php

use App\Domains\Catalog\Support\ItemTypeOptions;
use App\Models\BusinessPartner;
use App\Models\ItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes inactive type when editing an item that already uses it', function () {
    $active = ItemType::query()->create([
        'name' => '有効',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);
    $inactive = ItemType::query()->create([
        'name' => '無効',
        'message' => null,
        'is_active' => false,
        'owning_bp_id' => null,
    ]);

    $options = ItemTypeOptions::selectable(null, $inactive->id);

    expect($options->pluck('id')->all())
        ->toContain($active->id)
        ->toContain($inactive->id);
});

it('does not list inactive types without include id', function () {
    ItemType::query()->create([
        'name' => '有効のみ',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);
    $inactive = ItemType::query()->create([
        'name' => '無効除外',
        'message' => null,
        'is_active' => false,
        'owning_bp_id' => null,
    ]);

    $options = ItemTypeOptions::selectable(null, null);

    expect($options->pluck('id')->all())->not->toContain($inactive->id);
});

it('lists system and own bp types for a partner', function () {
    $bp = BusinessPartner::query()->create([
        'code' => 'BPN0001',
        'name' => 'Root',
        'is_active' => true,
    ]);
    $system = ItemType::query()->create([
        'name' => 'システム',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => null,
    ]);
    $own = ItemType::query()->create([
        'name' => '自社',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => $bp->id,
    ]);
    $other = ItemType::query()->create([
        'name' => '他社',
        'message' => null,
        'is_active' => true,
        'owning_bp_id' => BusinessPartner::query()->create([
            'code' => 'BPN0002',
            'name' => 'Other',
            'is_active' => true,
        ])->id,
    ]);

    $options = ItemTypeOptions::selectable($bp->id);

    expect($options->pluck('id')->all())
        ->toContain($system->id)
        ->toContain($own->id)
        ->not->toContain($other->id);
});
