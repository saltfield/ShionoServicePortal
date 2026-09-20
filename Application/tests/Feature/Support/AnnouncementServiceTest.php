<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Support\Enums\AnnouncementTargetType;
use App\Domains\Support\Services\AnnouncementService;
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

function announcementFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Announce BP');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $bp->id,
        'name' => 'Announce Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create(['login_id' => 'ANNADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($bp)->create(['login_id' => 'ANNBP', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $bp->id);

    $customerUser = User::factory()->customer($customer)->create(['login_id' => 'ANNCN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $customer->id);

    return compact('bp', 'customer', 'admin', 'bpUser', 'customerUser');
}

it('publishes announcement to customer and tracks unread', function () {
    $fx = announcementFixture();
    $service = app(AnnouncementService::class);

    $announcement = $service->publish($fx['bpUser'], 'メンテ通知', '今夜メンテします', [
        ['type' => AnnouncementTargetType::Customer->value, 'id' => $fx['customer']->id],
    ]);

    expect($announcement->targets)->toHaveCount(1)
        ->and($service->unreadCount($fx['customerUser']))->toBe(1)
        ->and($service->isVisibleTo($fx['customerUser'], $announcement))->toBeTrue();

    $service->markRead($fx['customerUser'], $announcement);

    expect($service->unreadCount($fx['customerUser']))->toBe(0);
});

it('rejects all-target publish by bp', function () {
    $fx = announcementFixture();
    $service = app(AnnouncementService::class);

    expect(fn () => $service->publish($fx['bpUser'], '全体', '本文', [
        ['type' => AnnouncementTargetType::All->value],
    ]))->toThrow(InvalidArgumentException::class, '全体配信');
});
