<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Enums\ApplicationStatus;
use App\Domains\Contract\Enums\ContractStatus;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Application;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function contractFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Child');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Contract Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);

    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社',
        'billing_name' => '請求先',
        'billing_address' => '東京都',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'CTRADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($child)->create(['login_id' => 'CTRBP', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);

    $parentUser = User::factory()->bp($parent)->create(['login_id' => 'CTRPARENT', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $catalog = app(CatalogPricingService::class);
    $initial = $catalog->createItem($admin, [
        'name' => '回線イニシャル',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 3000,
        'user_price' => 5000,
    ]);
    $running = $catalog->createItem($admin, [
        'name' => '回線月額',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $initial->id,
        'partition_price' => 2000,
        'user_price' => 4000,
    ]);

    app(CatalogPricingService::class)->upsertWholesalePrice($parentUser, $running, $parent, $child, 1800);

    return compact('parent', 'child', 'customer', 'site', 'admin', 'bpUser', 'parentUser', 'initial', 'running');
}

it('creates draft with required items and parent wholesale partition', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);

    expect(fn () => $service->createDraft($fx['bpUser'], $fx['site'], [$fx['running']->id]))
        ->toThrow(InvalidArgumentException::class, '必須セット');

    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    expect($contract->status)->toBe(ContractStatus::Draft)
        ->and($contract->code)->toStartWith('CTR')
        ->and($contract->items)->toHaveCount(2);

    $runningLine = $contract->items->firstWhere('item_id', $fx['running']->id);
    expect($runningLine->partition_price)->toBe(1800)
        ->and($runningLine->unit_price)->toBe(4000)
        ->and($runningLine->price_locked)->toBeFalse();
});

it('submits price approval and locks after parent approves', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    $service->updateDraftPrices($fx['bpUser'], $contract, [
        $contract->items->first()->id => ['partition_price' => 1900],
    ]);

    $application = $service->submitPriceApproval($fx['bpUser'], $contract->fresh());
    expect($application->status)->toBe(ApplicationStatus::Pending)
        ->and($contract->fresh()->status)->toBe(ContractStatus::PendingPriceApproval);

    $service->decidePriceApproval($fx['parentUser'], $application, true);
    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Approved)
        ->and($contract->items->every(fn ($line) => $line->price_locked))->toBeTrue();
});

it('allows price change application after lock', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $application = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $application, true);
    $contract->refresh();

    $line = $contract->items->first();
    $change = $service->submitPriceChange($fx['bpUser'], $contract, [
        $line->id => ['unit_price' => 4500, 'partition_price' => 2100],
    ]);

    expect($change->type->value)->toBe('price_change')
        ->and($change->status)->toBe(ApplicationStatus::Pending);

    $service->decidePriceChange($fx['parentUser'], $change, true);
    expect($line->fresh()->unit_price)->toBe(4500)
        ->and($line->fresh()->partition_price)->toBe(2100)
        ->and($line->fresh()->price_locked)->toBeTrue();
});

it('stores contract item data with master or free name', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);

    $master = \App\Models\DataFieldName::query()->create([
        'name' => '回線番号',
        'replace_code' => 'line_id',
        'is_active' => true,
    ]);
    $line = $contract->fresh()->items->first();

    $service->upsertItemData($fx['bpUser'], $line, [
        ['data_field_name_id' => $master->id, 'name' => '', 'value' => '03-1234-5678'],
        ['name' => '設置場所メモ', 'replace_code' => 'install_memo', 'value' => '1F配線盤'],
    ]);

    $rows = $line->fresh()->dataRows;
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->name)->toBe('回線番号')
        ->and($rows[0]->replace_code)->toBe('line_id')
        ->and($rows[0]->value)->toBe('03-1234-5678')
        ->and($rows[1]->name)->toBe('設置場所メモ')
        ->and($rows[1]->replace_code)->toBe('install_memo');
});

it('deletes draft contracts only', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    $service->deleteDraft($fx['bpUser'], $contract);
    expect(Contract::query()->whereKey($contract->id)->exists())->toBeFalse();

    $contract2 = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract2);
    $service->decidePriceApproval($fx['parentUser'], $app, true);

    expect(fn () => $service->deleteDraft($fx['bpUser'], $contract2->fresh()))
        ->toThrow(InvalidArgumentException::class, '下書き');
});
