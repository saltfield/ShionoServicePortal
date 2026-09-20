<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Services\BillingService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
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

function billingFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $parent = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Bill Parent');
    $child = $hierarchy->createChild($parent, $seq->next(PartnerCodePrefix::Bpn), 'Bill Child');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $child->id,
        'name' => 'Bill Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);
    $site = Site::query()->create([
        'customer_id' => $customer->id,
        'name' => '本社',
        'billing_name' => '請求先',
        'is_primary' => true,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'BILLADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);
    $bpUser = User::factory()->bp($child)->create(['login_id' => 'BILLBP', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $child->id);
    $parentUser = User::factory()->bp($parent)->create(['login_id' => 'BILLPARENT', 'password' => 'Password123!', 'must_change_password' => false]);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $parent->id);

    $catalog = app(CatalogPricingService::class);
    $initial = $catalog->createItem($admin, [
        'name' => '開通費',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 1000,
        'user_price' => 3000,
        'tax_rate' => 10,
    ]);
    $running = $catalog->createItem($admin, [
        'name' => '月額',
        'billing_type' => BillingType::Running->value,
        'required_item_id' => $initial->id,
        'partition_price' => 2000,
        'user_price' => 5000,
        'tax_rate' => 10,
    ]);

    $contracts = app(ContractService::class);
    $contract = $contracts->createDraft($bpUser, $site, [$initial->id, $running->id]);
    $app = $contracts->submitPriceApproval($bpUser, $contract);
    $contracts->decidePriceApproval($parentUser, $app, true);
    $contracts->activate($bpUser, $contract->fresh());

    return [
        'admin' => $admin,
        'bpUser' => $bpUser,
        'parentUser' => $parentUser,
        'contract' => $contract->fresh(),
        'customer' => $customer,
        'activatedYm' => $contract->fresh()->activated_at->timezone(config('app.timezone'))->format('Ym'),
    ];
}

it('issues invoice with initial and running lines for activation month', function () {
    $fx = billingFixture();
    $invoice = app(BillingService::class)->issueFromContract($fx['bpUser'], $fx['contract'], $fx['activatedYm']);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->subtotal)->toBe(8000)
        ->and($invoice->tax_total)->toBe(800)
        ->and($invoice->total)->toBe(8800)
        ->and(AuditLog::query()->where('action', 'invoice.issue')->exists())->toBeTrue();
});

it('issues only running lines for later months', function () {
    $fx = billingFixture();
    $later = now()->addMonthNoOverflow()->format('Ym');
    if ($later === $fx['activatedYm']) {
        $later = now()->addMonthsNoOverflow(2)->format('Ym');
    }

    $invoice = app(BillingService::class)->issueFromContract($fx['bpUser'], $fx['contract'], $later);

    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->billing_type)->toBe(BillingType::Running)
        ->and($invoice->subtotal)->toBe(5000);
});

it('marks paid and cancels with constraints', function () {
    $fx = billingFixture();
    $billing = app(BillingService::class);
    $invoice = $billing->issueFromContract($fx['bpUser'], $fx['contract'], $fx['activatedYm']);

    $billing->markPaid($fx['bpUser'], $invoice);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    expect(fn () => $billing->cancel($fx['bpUser'], $invoice->fresh()))
        ->toThrow(InvalidArgumentException::class);

    $later = now()->addMonthNoOverflow()->format('Ym');
    if ($later === $fx['activatedYm']) {
        $later = now()->addMonthsNoOverflow(2)->format('Ym');
    }
    $second = $billing->issueFromContract($fx['bpUser'], $fx['contract'], $later);
    $billing->cancel($fx['bpUser'], $second);
    expect($second->fresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('scopes customer visibility to own invoices', function () {
    $fx = billingFixture();
    $invoice = app(BillingService::class)->issueFromContract($fx['bpUser'], $fx['contract'], $fx['activatedYm']);

    $customerUser = User::factory()->customer($fx['customer'])->create([
        'login_id' => 'BILLCUS',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $fx['customer']->id);

    expect(app(BillingService::class)->visibleQuery($customerUser)->whereKey($invoice->id)->exists())->toBeTrue();

    $this->post(route('customer.login.store'), [
        'login_id' => 'BILLCUS',
        'cn' => $fx['customer']->code,
        'password' => 'Password123!',
    ])->assertRedirect(route('customer.dashboard'));

    $this->get(route('customer.invoices.index'))->assertOk()->assertSee($invoice->code);
    $this->get(route('customer.invoices.show', $invoice))->assertOk();
});
