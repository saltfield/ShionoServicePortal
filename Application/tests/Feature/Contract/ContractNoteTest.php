<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Enums\ContractNoteVisibility;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Services\EntityNoteService;
use App\Models\Customer;
use App\Models\EntityNote;
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

function entityNoteFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Child');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Note Customer',
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

    $admin = User::factory()->admin()->create([
        'login_id' => 'NOTEADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($child)->create([
        'login_id' => 'NOTEBP',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);

    $parentUser = User::factory()->bp($parent)->create([
        'login_id' => 'NOTEPARENT',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $catalog = app(CatalogPricingService::class);
    $initial = $catalog->createItem($admin, [
        'name' => '備考イニシャル',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 3000,
        'user_price' => 5000,
    ]);
    $running = $catalog->createItem($admin, [
        'name' => '備考月額',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $initial->id,
        'partition_price' => 2000,
        'user_price' => 4000,
    ]);

    return compact('parent', 'child', 'customer', 'site', 'admin', 'bpUser', 'parentUser', 'initial', 'running');
}

it('stores shared and organization notes separately with large body capacity', function () {
    $fx = entityNoteFixture();
    $service = app(EntityNoteService::class);
    $contract = app(ContractService::class)
        ->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    $long = str_repeat('あ', 5000);
    $shared = $service->upsertShared($fx['bpUser'], $contract, $long);
    $org = $service->upsertOrganization($fx['bpUser'], $contract, 'BPだけのメモ');

    expect($shared->visibility)->toBe(ContractNoteVisibility::Shared)
        ->and($shared->subject_type)->toBe('contract')
        ->and(mb_strlen((string) $shared->body))->toBe(5000)
        ->and($org->visibility)->toBe(ContractNoteVisibility::Organization)
        ->and($org->owner_type)->toBe('bp')
        ->and($org->owner_id)->toBe($fx['child']->id);

    expect($service->organizationNote($contract, $fx['parentUser']))->toBeNull();
    expect($service->sharedNote($contract)?->body)->toBe($long);

    $adminOrg = $service->upsertOrganization($fx['admin'], $contract, '管理者メモ');
    expect($adminOrg->owner_type)->toBe('admin')
        ->and($service->organizationNote($contract, $fx['admin'])?->body)->toBe('管理者メモ')
        ->and($service->organizationNote($contract, $fx['bpUser'])?->body)->toBe('BPだけのメモ');
});

it('allows bp and customer to update contract notes via http and hides foreign organization notes', function () {
    $fx = entityNoteFixture();
    $contract = app(ContractService::class)
        ->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    $this->actingAs($fx['bpUser'], 'bp')
        ->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('共有備考')
        ->assertSee('組織内備考')
        ->assertSee('sharedNoteConfirmDialog', false);

    $this->actingAs($fx['bpUser'], 'bp')
        ->put(route('bp.contracts.notes.shared', $contract), ['body' => '共有テキスト'])
        ->assertRedirect(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'notes']));

    $this->actingAs($fx['bpUser'], 'bp')
        ->put(route('bp.contracts.notes.organization', $contract), ['body' => 'BP内メモ'])
        ->assertRedirect();

    expect(EntityNote::query()->where('visibility', 'shared')->where('subject_type', 'contract')->where('subject_id', $contract->id)->value('body'))
        ->toBe('共有テキスト');

    $customerUser = User::factory()->customer($fx['customer'])->create([
        'login_id' => 'NOTECUST',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($customerUser, 'customer_owner', RoleScope::Customer, $fx['customer']->id);

    $this->actingAs($customerUser, 'customer')
        ->get(route('customer.contracts.show', ['contract' => $contract, 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('共有テキスト')
        ->assertDontSee('BP内メモ');

    $this->actingAs($customerUser, 'customer')
        ->put(route('customer.contracts.notes.organization', $contract), ['body' => '顧客メモ'])
        ->assertRedirect();

    $this->actingAs($fx['bpUser'], 'bp')
        ->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('BP内メモ')
        ->assertDontSee('顧客メモ');
});

it('supports notes on bp and customer detail pages', function () {
    $fx = entityNoteFixture();
    $service = app(EntityNoteService::class);

    $service->upsertShared($fx['admin'], $fx['child'], 'BP共有');
    $service->upsertOrganization($fx['bpUser'], $fx['child'], 'BP組織');
    $service->upsertShared($fx['bpUser'], $fx['customer'], 'CN共有');
    $service->upsertOrganization($fx['admin'], $fx['customer'], '管理者CNメモ');

    $this->actingAs($fx['bpUser'], 'bp')
        ->get(route('bp.business-partners.show', ['businessPartner' => $fx['child'], 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('BP共有')
        ->assertSee('BP組織');

    $this->actingAs($fx['parentUser'], 'bp')
        ->get(route('bp.business-partners.show', ['businessPartner' => $fx['child'], 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('BP共有')
        ->assertDontSee('BP組織');

    $this->actingAs($fx['bpUser'], 'bp')
        ->put(route('bp.customers.notes.shared', $fx['customer']), ['body' => 'CN共有更新'])
        ->assertRedirect(route('bp.customers.show', ['customer' => $fx['customer'], 'tab' => 'notes']));

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.customers.show', ['customer' => $fx['customer'], 'tab' => 'notes']))
        ->assertOk()
        ->assertSee('CN共有更新')
        ->assertSee('管理者CNメモ');

    expect(EntityNote::query()->where('subject_type', 'bp')->where('subject_id', $fx['child']->id)->count())->toBe(2)
        ->and(EntityNote::query()->where('subject_type', 'customer')->where('subject_id', $fx['customer']->id)->count())->toBe(2);
});
