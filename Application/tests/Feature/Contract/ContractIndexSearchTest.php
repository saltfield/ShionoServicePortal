<?php

use App\Domains\Contract\Services\ContractService;
use App\Models\Contract;
use App\Models\ContractData;
use App\Models\ContractItemData;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

it('filters contracts by field search with and/or and wildcards', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);

    $contractA = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $contractB = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    Contract::query()->whereKey($contractA->id)->update(['code' => 'CTRSEARCH001']);
    Contract::query()->whereKey($contractB->id)->update(['code' => 'CTROTHER002']);

    ContractData::query()->create([
        'contract_id' => $contractA->id,
        'name' => '回線番号',
        'replace_code' => 'caf_cop',
        'value' => 'CAF12345678',
        'sort_order' => 1,
    ]);

    $lineB = $contractB->fresh()->items->first();
    ContractItemData::query()->create([
        'contract_item_id' => $lineB->id,
        'name' => '回線番号',
        'replace_code' => 'caf_cop',
        'value' => 'CAF99999999',
        'sort_order' => 1,
    ]);

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.contracts.index', [
            'contract_code' => 'CTRSEARCH*',
            'data_value' => 'CAF123*',
            'match' => 'and',
        ]))
        ->assertOk()
        ->assertSee('CTRSEARCH001')
        ->assertDontSee('CTROTHER002');

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.contracts.index', [
            'contract_code' => 'NOMATCH',
            'data_value' => 'CAF999*',
            'match' => 'or',
        ]))
        ->assertOk()
        ->assertSee('CTROTHER002')
        ->assertDontSee('CTRSEARCH001');

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.contracts.index', [
            'data_name' => '回線',
            'item_name' => '回線月額',
            'match' => 'and',
        ]))
        ->assertOk()
        ->assertSee('CTRSEARCH001')
        ->assertSee('CTROTHER002');

    $this->actingAs($fx['bpUser'], 'bp')
        ->get(route('bp.contracts.index', [
            'cn' => $fx['customer']->code,
            'bpn' => $fx['child']->code,
        ]))
        ->assertOk()
        ->assertSee('CTRSEARCH001')
        ->assertSee('CTROTHER002');
});
