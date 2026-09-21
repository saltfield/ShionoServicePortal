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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('shows only the customer contracts on customer detail tab while header lists all scoped contracts', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $own = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);

    $otherCustomer = Customer::query()->create([
        'code' => app(NumberSequenceService::class)->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $fx['site']->customer->managing_bp_id,
        'name' => 'Other Contract Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $otherSite = Site::query()->create([
        'customer_id' => $otherCustomer->id,
        'name' => '別拠点',
        'billing_name' => '請求先',
        'billing_address' => '大阪府',
        'is_primary' => true,
        'is_active' => true,
    ]);
    $other = $service->createDraft($fx['bpUser'], $otherSite, [$fx['initial']->id, $fx['running']->id]);

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('bp.dashboard'));

    $this->get(route('bp.contracts.index'))
        ->assertOk()
        ->assertSee($own->code)
        ->assertSee($other->code);

    $this->get(route('bp.customers.show', ['customer' => $fx['site']->customer_id, 'tab' => 'contracts']))
        ->assertOk()
        ->assertSee('契約')
        ->assertSee($own->code)
        ->assertDontSee($other->code)
        ->assertSee('新規申込')
        ->assertSee('return_customer_id='.$fx['site']->customer_id);

    $this->get(route('bp.contracts.show', ['contract' => $own, 'return_customer_id' => $fx['site']->customer_id]))
        ->assertOk()
        ->assertSee('カスタマー詳細へ')
        ->assertSee(route('bp.customers.show', ['customer' => $fx['site']->customer_id, 'tab' => 'contracts'], false));

    $this->get(route('bp.contracts.show', $own))
        ->assertOk()
        ->assertSee('一覧へ')
        ->assertDontSee('カスタマー詳細へ');
});

it('marks service provided with first billing month and allows messages until then', function () {
    $fx = contractFixture();
    $fx['running']->update(['minimum_term_months' => 12]);
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);
    $contract = $contract->fresh();

    $message = $service->postMessage($fx['bpUser'], $contract, '現地調査が必要です');
    expect($message->body)->toBe('現地調査が必要です')
        ->and($service->canPostMessages($contract))->toBeTrue();

    $service->activate($fx['bpUser'], $contract, '202601');
    $contract = $contract->fresh();

    expect($contract->status)->toBe(ContractStatus::Activated)
        ->and($contract->first_billing_year_month)->toBe('202601')
        ->and($contract->minimum_term_months_snapshot)->toBe(12)
        ->and($contract->status->label())->toBe('サービス提供開始')
        ->and($service->canPostMessages($contract))->toBeFalse();

    expect(fn () => $service->postMessage($fx['bpUser'], $contract, 'まだ投稿'))
        ->toThrow(InvalidArgumentException::class, 'サービス提供開始後');

    $service->revertServiceProvided($fx['bpUser'], $contract->fresh());
    expect($contract->fresh()->status)->toBe(ContractStatus::Approved)
        ->and($service->canPostMessages($contract->fresh()))->toBeTrue()
        ->and($contract->fresh()->first_billing_year_month)->toBe('202601');
});

it('suggests residual lump-sum and cancels with manual amount', function () {
    $fx = contractFixture();
    $fx['running']->update(['minimum_term_months' => 12]);
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);
    $service->activate($fx['bpUser'], $contract->fresh(), '202601');
    $contract = $contract->fresh();

    $suggestion = $service->suggestCancellationAmount($contract, '202606');
    // elapsed inclusive Jan-Jun = 6, remaining = 12-6 = 6, amount = unit_price * 6
    expect($suggestion['elapsed_months'])->toBe(6)
        ->and($suggestion['remaining_months'])->toBe(6)
        ->and($suggestion['suggested_amount'])->toBe((int) $contract->items->firstWhere('item_id', $fx['running']->id)->unit_price * 6);

    $service->cancel($fx['bpUser'], $contract, '202606', 12345, '残期間一括');
    $contract = $contract->fresh();

    expect($contract->status)->toBe(ContractStatus::Cancelled)
        ->and($contract->final_billing_year_month)->toBe('202606')
        ->and($contract->cancellation_amount)->toBe(12345)
        ->and($contract->cancellation_note)->toBe('残期間一括')
        ->and($contract->status->label())->toBe('解約');
});

it('shows contract detail tabs and service provide button label', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.contracts.show', $contract->fresh()))
        ->assertOk()
        ->assertSee('概要')
        ->assertSee('メッセージ')
        ->assertSee('サービス提供開始');

    $this->post(route('bp.contracts.activate', $contract->fresh()), [
        'first_billing_mode' => 'custom',
        'first_billing_year_month' => '202603',
    ])->assertRedirect(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'overview']));

    expect($contract->fresh()->first_billing_year_month)->toBe('202603');

    $this->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'messages']))
        ->assertOk()
        ->assertSee('サービス提供開始後はメッセージを投稿できません');
});

it('allows staff sample document generation on data tab and blocks customer download until activated', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);
    $contract = $contract->fresh();

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertOk()
        ->assertSee('サンプル生成')
        ->assertSee('Document テンプレートがありません');

    $this->from(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->post(route('bp.contracts.regenerate-documents', $contract))
        ->assertRedirect(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertSessionHasErrors('contract');

    $line = $contract->fresh()->items->first();
    $doc = \App\Models\ContractItemDocument::query()->create([
        'contract_item_id' => $line->id,
        'title' => 'サンプル案内',
        'file_path' => 'contract-documents/sample.pdf',
        'original_name' => 'sample.pdf',
        'mime_type' => 'application/pdf',
    ]);
    \Illuminate\Support\Facades\Storage::disk('local')->put($doc->file_path, '%PDF-1.4 sample');

    $customerUser = User::factory()->customer($fx['site']->customer)->create([
        'login_id' => 'CTRCUSTOMER',
        'password' => 'Password123!',
        'is_active' => true,
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $fx['site']->customer_id);

    $this->post(route('bp.logout'));
    $this->post(route('customer.login.store'), [
        'login_id' => 'CTRCUSTOMER',
        'cn' => $fx['site']->customer->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('customer.dashboard'));

    $this->get(route('customer.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertOk()
        ->assertSee('資料のダウンロードはサービス提供開始後に利用できます')
        ->assertDontSee('サンプル案内');

    $this->get(route('customer.contracts.items.documents.download', [$line, $doc->id]))
        ->assertForbidden();

    $this->post(route('customer.logout'));
    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);
    $service->activate($fx['bpUser'], $contract->fresh(), '202601');

    $this->post(route('bp.logout'));
    $this->post(route('customer.login.store'), [
        'login_id' => 'CTRCUSTOMER',
        'cn' => $fx['site']->customer->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('customer.contracts.show', ['contract' => $contract->fresh(), 'tab' => 'data']))
        ->assertOk()
        ->assertSee('サンプル案内');

    $this->get(route('customer.contracts.items.documents.download', [$line, $doc->id]))
        ->assertOk();
});

it('generates sample pdf when item has a template', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    Storage::fake('local');

    $template = $service->addItemDocument(
        $fx['admin'],
        $fx['running'],
        '開通案内',
        UploadedFile::fake()->createWithContent('guide.html', '<html><body>{{c_name}}</body></html>'),
    );

    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);
    $contract = $contract->fresh();

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);

    $this->post(route('bp.contracts.regenerate-documents', $contract))
        ->assertRedirect(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertSessionHas('status');

    $line = $contract->fresh()->items->firstWhere('item_id', $fx['running']->id);
    $doc = $line->documents->first();
    expect($doc)->not->toBeNull()
        ->and($doc->item_document_id)->toBe($template->id)
        ->and(Storage::disk('local')->exists($doc->file_path))->toBeTrue();

    $this->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertOk()
        ->assertSee('開通案内')
        ->assertSee('サンプル');

    $this->get(route('bp.contracts.items.documents.download', [$line, $doc->id]))
        ->assertOk();
});

it('allows staff to delete issued contract documents with confirmation code', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    Storage::fake('local');

    $service->addItemDocument(
        $fx['admin'],
        $fx['running'],
        '開通案内',
        UploadedFile::fake()->createWithContent('guide.html', '<html><body>{{c_name}}</body></html>'),
    );

    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);
    $contract = $contract->fresh();
    $service->regenerateDocuments($fx['bpUser'], $contract);
    $line = $contract->fresh()->items->firstWhere('item_id', $fx['running']->id);
    $doc = $line->documents->first();
    expect($doc)->not->toBeNull();

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->assertOk()
        ->assertSee('ドキュメント（PDF）')
        ->assertSee('開通案内')
        ->assertSee('削除');

    $code = session('contract_item_document_delete_confirm.'.$doc->id);
    expect($code)->toMatch('/^\d{4}$/');

    $this->from(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))
        ->delete(route('bp.contracts.items.documents.destroy', [$line, $doc->id]), [
            'confirmation_code' => '0000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation_code');

    $this->get(route('bp.contracts.show', ['contract' => $contract, 'tab' => 'data']))->assertOk();
    $code = session('contract_item_document_delete_confirm.'.$doc->id);

    $this->delete(route('bp.contracts.items.documents.destroy', [$line, $doc->id]), [
        'confirmation_code' => $code,
    ])->assertRedirect()->assertSessionHas('status');

    expect($line->fresh()->documents)->toHaveCount(0);
});

it('activates with this month and next month modes without typing YYYYMM', function () {
    $fx = contractFixture();
    $service = app(ContractService::class);
    $contract = $service->createDraft($fx['bpUser'], $fx['site'], [$fx['initial']->id, $fx['running']->id]);
    $app = $service->submitPriceApproval($fx['bpUser'], $contract);
    $service->decidePriceApproval($fx['parentUser'], $app, true);

    $this->post(route('bp.login.store'), [
        'login_id' => 'CTRBP',
        'bpn' => $fx['bpUser']->businessPartner->code,
        'password' => 'Password123!',
    ]);

    $this->get(route('bp.contracts.show', $contract->fresh()))
        ->assertOk()
        ->assertSee('今月')
        ->assertSee('来月')
        ->assertSee('任意指定');

    $thisMonth = now()->timezone(config('app.timezone'))->format('Ym');
    $this->post(route('bp.contracts.activate', $contract->fresh()), [
        'first_billing_mode' => 'this_month',
    ])->assertRedirect();
    expect($contract->fresh()->first_billing_year_month)->toBe($thisMonth);

    $service->revertServiceProvided($fx['bpUser'], $contract->fresh());
    $nextMonth = now()->timezone(config('app.timezone'))->copy()->addMonthNoOverflow()->format('Ym');
    $this->post(route('bp.contracts.activate', $contract->fresh()), [
        'first_billing_mode' => 'next_month',
    ])->assertRedirect();
    expect($contract->fresh()->first_billing_year_month)->toBe($nextMonth);
});
