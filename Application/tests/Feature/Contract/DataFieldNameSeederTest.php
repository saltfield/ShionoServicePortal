<?php

use App\Models\DataFieldName;
use Database\Seeders\DataFieldNameSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds default data field names', function () {
    $this->seed(DataFieldNameSeeder::class);

    $defaults = DataFieldNameSeeder::defaults();
    expect(DataFieldName::query()->count())->toBe(count($defaults));

    foreach ($defaults as $row) {
        $master = DataFieldName::query()->where('replace_code', $row['replace_code'])->first();
        expect($master)->not->toBeNull()
            ->and($master->name)->toBe($row['name'])
            ->and($master->is_active)->toBeTrue();
    }
});

it('is idempotent when seeded twice', function () {
    $this->seed(DataFieldNameSeeder::class);
    $this->seed(DataFieldNameSeeder::class);

    expect(DataFieldName::query()->count())->toBe(count(DataFieldNameSeeder::defaults()));
});
