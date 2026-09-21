<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\InquiryAssigneeType;
use App\Domains\Support\Enums\InquiryStatus;
use App\Domains\Support\Enums\InquiryVisibility;
use App\Domains\Support\Services\InquiryService;
use App\Models\Customer;
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

it('lets customer open ticket to managing bp and bp reply moves to in progress', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['customerUser'], '開通について', 'いつ開通しますか？');

    expect($inquiry->status)->toBe(InquiryStatus::Submitted)
        ->and($inquiry->code)->toStartWith('TKT')
        ->and($inquiry->assignee_type)->toBe(InquiryAssigneeType::Bp)
        ->and($inquiry->assignee_bp_id)->toBe($fx['bp']->id)
        ->and($inquiry->customer_id)->toBe($fx['customer']->id)
        ->and($inquiry->visibility)->toBe(InquiryVisibility::Organization)
        ->and($inquiry->messages)->toHaveCount(1);

    $message = $service->reply($fx['bpUser'], $inquiry->fresh(), '明日開通予定です。');

    expect($message->body)->toBe('明日開通予定です。')
        ->and($inquiry->fresh()->status)->toBe(InquiryStatus::InProgress)
        ->and($inquiry->fresh()->messages)->toHaveCount(3);
});

it('routes root bp ticket to admin and counts unread for assignee', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['bpUser'], '上位相談', '管理者へ確認したいです');

    expect($inquiry->assignee_type)->toBe(InquiryAssigneeType::Admin)
        ->and($inquiry->assignee_bp_id)->toBeNull()
        ->and($inquiry->issuer_bp_id)->toBe($fx['bp']->id);

    expect($service->unreadReceivedCount($fx['admin']))->toBe(1)
        ->and($service->unreadIssuedCount($fx['bpUser']))->toBe(0);

    $service->reply($fx['admin'], $inquiry->fresh(), '確認しました');

    expect($service->unreadIssuedCount($fx['bpUser']))->toBe(1)
        ->and($service->unreadReceivedCount($fx['admin']))->toBe(0);
});

it('allows withdraw only by opener while submitted', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['customerUser'], '取消予定', '取り下げます');
    $service->withdraw($fx['customerUser'], $inquiry->fresh());

    expect($inquiry->fresh()->status)->toBe(InquiryStatus::Withdrawn)
        ->and(fn () => $service->reply($fx['bpUser'], $inquiry->fresh(), '返信'))
        ->toThrow(InvalidArgumentException::class);
});

it('denies reply after close unless reopened', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $inquiry = $service->open($fx['customerUser'], '請求', '明細を教えてください');
    $service->reply($fx['bpUser'], $inquiry->fresh(), '確認します');
    $service->close($fx['bpUser'], $inquiry->fresh());

    expect(fn () => $service->reply($fx['customerUser'], $inquiry->fresh(), '追加質問'))
        ->toThrow(InvalidArgumentException::class);

    $service->reopen($fx['bpUser'], $inquiry->fresh());
    $service->reply($fx['customerUser'], $inquiry->fresh(), '追加質問');

    expect($inquiry->fresh()->status)->toBe(InquiryStatus::InProgress);
});

it('stores attachments within limits', function () {
    Storage::fake('local');
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    $file = UploadedFile::fake()->create('memo.pdf', 100, 'application/pdf');
    $inquiry = $service->open(
        $fx['customerUser'],
        '添付あり',
        'ファイルを送ります',
        InquiryVisibility::Organization,
        null,
        false,
        [$file],
    );

    expect($inquiry->messages->first()->attachments)->toHaveCount(1)
        ->and($inquiry->messages->first()->attachments->first()->original_name)->toBe('memo.pdf');
});

it('rejects admin ticket open', function () {
    $fx = inquiryFixture();
    $service = app(InquiryService::class);

    expect(fn () => $service->open($fx['admin'], 'NG', '管理者は起票不可'))
        ->toThrow(InvalidArgumentException::class);
});
