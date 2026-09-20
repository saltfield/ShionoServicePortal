<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Services\InquiryService;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function inquiryFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Support BP');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $bp->id,
        'name' => 'Inquiry Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'INQADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($bp)->create(['login_id' => 'INQBP', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($bpUser, 'bp_support', RoleScope::Bp, $bp->id);

    $customerUser = User::factory()->customer($customer)->create(['login_id' => 'INQCN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $customer->id);

    return compact('bp', 'customer', 'admin', 'bpUser', 'customerUser');
}

it('lets customer open inquiry to managing bp and bp reply', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['customerUser'], '開通について', 'いつ開通しますか？');

    expect($inquiry->status)->toBe(InquiryStatus::Open)
        ->and($inquiry->owning_bp_id)->toBe($fx['bp']->id)
        ->and($inquiry->customer_id)->toBe($fx['customer']->id)
        ->and($inquiry->messages)->toHaveCount(1);

    $message = $service->reply($fx['bpUser'], $inquiry->fresh(), '明日開通予定です。');

    expect($message->body)->toBe('明日開通予定です。')
        ->and($inquiry->fresh()->status)->toBe(InquiryStatus::InProgress);
});

it('denies reply after close unless reopened', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['customerUser'], '請求', '明細を教えてください');
    $service->close($fx['bpUser'], $inquiry->fresh());

    expect(fn () => $service->reply($fx['customerUser'], $inquiry->fresh(), '追加質問'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    $service->reopen($fx['bpUser'], $inquiry->fresh());
    $service->reply($fx['customerUser'], $inquiry->fresh(), '追加質問');

    expect($inquiry->fresh()->messages)->toHaveCount(2)
        ->and($inquiry->fresh()->status)->toBe(InquiryStatus::InProgress);
});
