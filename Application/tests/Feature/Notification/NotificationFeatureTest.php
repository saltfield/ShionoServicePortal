<?php

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Catalog\Enums\BillingType;
use App\Domains\Catalog\Services\CatalogPricingService;
use App\Domains\Contract\Services\ContractService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\AdminPrivilegeService;
use App\Domains\Iam\Services\RbacService;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Services\NotificationDispatcher;
use App\Domains\Support\Services\InquiryService;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\ContractApplicationNotification;
use App\Notifications\ForcePasswordChangeNotification;
use App\Notifications\TicketMessageNotification;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function notificationFixture(): array
{
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Notify BP');

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $bp->id,
        'name' => 'Notify Customer',
        'entity_type' => 'corporate',
        'two_factor_mode' => TwoFactorMode::Optional,
        'is_active' => true,
    ]);

    $admin = User::factory()->admin()->create([
        'login_id' => 'NOTIFADMIN',
        'password' => 'Password123!',
        'email' => 'admin-notify@example.com',
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $bpUser = User::factory()->bp($bp)->create([
        'login_id' => 'NOTIFBP',
        'password' => 'Password123!',
        'email' => 'bp-notify@example.com',
    ]);
    app(RbacService::class)->assignRole($bpUser, 'bp_support', RoleScope::Bp, $bp->id);

    $customerUser = User::factory()->customer($customer)->create([
        'login_id' => 'NOTIFCN',
        'password' => 'Password123!',
        'email' => 'customer-notify@example.com',
    ]);
    app(RbacService::class)->assignRole($customerUser, 'customer_member', RoleScope::Customer, $customer->id);

    return compact('bp', 'customer', 'admin', 'bpUser', 'customerUser');
}

it('queues ticket open notification to assignee side', function () {
    Notification::fake();
    $fx = notificationFixture();

    app(InquiryService::class)->open($fx['customerUser'], '開通', 'いつ開通しますか？');

    Notification::assertSentTo(
        $fx['bpUser'],
        TicketMessageNotification::class,
        fn (TicketMessageNotification $n) => $n->isOpened === true
    );
    Notification::assertNotSentTo($fx['customerUser'], TicketMessageNotification::class);
});

it('skips ticket notification when recipient opted out', function () {
    Notification::fake();
    $fx = notificationFixture();

    UserNotificationPreference::query()->create([
        'user_id' => $fx['bpUser']->id,
        'notification_type' => NotificationType::TicketMessage->value,
        'enabled' => false,
    ]);

    app(InquiryService::class)->open($fx['customerUser'], '開通', '本文');

    Notification::assertNotSentTo($fx['bpUser'], TicketMessageNotification::class);
});

it('always sends force password notification even if preference row is off', function () {
    Notification::fake();
    $fx = notificationFixture();

    UserNotificationPreference::query()->create([
        'user_id' => $fx['bpUser']->id,
        'notification_type' => NotificationType::SecurityPasswordForce->value,
        'enabled' => false,
    ]);

    app(AdminPrivilegeService::class)->forcePasswordChange($fx['admin'], $fx['bpUser']);

    Notification::assertSentTo($fx['bpUser'], ForcePasswordChangeNotification::class);
});

it('saves opt-out preferences from settings screen', function () {
    $fx = notificationFixture();

    $this->actingAs($fx['bpUser'], 'bp')
        ->post(route('bp.notification-settings.update'), [
            'preferences' => [
                NotificationType::TicketMessage->value => '0',
                NotificationType::ContractApplication->value => '1',
            ],
        ])
        ->assertRedirect(route('bp.notification-settings.edit'));

    $prefs = app(NotificationDispatcher::class)->preferencesFor($fx['bpUser']->fresh());

    expect($prefs[NotificationType::TicketMessage->value])->toBeFalse()
        ->and($prefs[NotificationType::ContractApplication->value])->toBeTrue()
        ->and($prefs[NotificationType::SecurityPasswordForce->value])->toBeTrue();
});

it('allows admin to change notification preferences on user edit', function () {
    $fx = notificationFixture();

    $this->actingAs($fx['admin'], 'admin')
        ->get(route('admin.users.edit', $fx['bpUser']))
        ->assertOk()
        ->assertSee('メール通知設定');

    $this->actingAs($fx['admin'], 'admin')
        ->put(route('admin.users.update', $fx['bpUser']), [
            'name' => $fx['bpUser']->name ?: 'BP User',
            'email' => $fx['bpUser']->email,
            'is_active' => '1',
            'notification_preferences_present' => '1',
            'preferences' => [
                NotificationType::TicketMessage->value => '1',
                // contract.application intentionally omitted => off
            ],
        ])
        ->assertRedirect();

    $prefs = app(NotificationDispatcher::class)->preferencesFor($fx['bpUser']->fresh());

    expect($prefs[NotificationType::TicketMessage->value])->toBeTrue()
        ->and($prefs[NotificationType::ContractApplication->value])->toBeFalse();
});

it('notifies when must_change_password is turned on via user edit', function () {
    Notification::fake();
    $fx = notificationFixture();
    $fx['bpUser']->forceFill(['must_change_password' => false])->save();

    $this->actingAs($fx['admin'], 'admin')
        ->put(route('admin.users.update', $fx['bpUser']), [
            'name' => $fx['bpUser']->name ?: 'BP User',
            'email' => $fx['bpUser']->email,
            'is_active' => '1',
            'must_change_password' => '1',
        ])
        ->assertRedirect();

    Notification::assertSentTo($fx['bpUser'], ForcePasswordChangeNotification::class);
});

it('notifies when email is added while force password is already on', function () {
    Notification::fake();
    $fx = notificationFixture();
    $fx['bpUser']->forceFill([
        'must_change_password' => true,
        'email' => null,
    ])->save();

    $this->actingAs($fx['admin'], 'admin')
        ->put(route('admin.users.update', $fx['bpUser']), [
            'name' => $fx['bpUser']->name ?: 'BP User',
            'email' => 'newly-added@example.com',
            'is_active' => '1',
            'must_change_password' => '1',
        ])
        ->assertRedirect();

    Notification::assertSentTo($fx['bpUser']->fresh(), ForcePasswordChangeNotification::class);
});

it('skips force password mail and warns when target has no email', function () {
    Notification::fake();
    $fx = notificationFixture();
    $fx['bpUser']->forceFill(['email' => null, 'must_change_password' => false])->save();

    $this->actingAs($fx['admin'], 'admin')
        ->post(route('admin.users.force-password', $fx['bpUser']))
        ->assertRedirect()
        ->assertSessionHas('status', fn ($status) => str_contains($status, 'メールアドレス未設定'));

    expect($fx['bpUser']->fresh()->must_change_password)->toBeTrue();
    Notification::assertNothingSent();
});

it('skips users without email and logs reason', function () {
    Notification::fake();
    $fx = notificationFixture();
    $fx['bpUser']->forceFill(['email' => null])->save();

    app(InquiryService::class)->open($fx['customerUser'], '開通', '本文');

    Notification::assertNothingSent();
});

it('notifies parent bp approvers when child submits price approval', function () {
    Notification::fake();

    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp1 = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Notify BP1');
    $bp2 = $hierarchy->createChild($bp1, $seq->next(PartnerCodePrefix::Bpn), 'Notify BP2');

    $parentUser = User::factory()->bp($bp1)->create([
        'login_id' => 'NOTIFYBP1',
        'email' => 'bp1-approve@example.com',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $bp1->id);

    $childUser = User::factory()->bp($bp2)->create([
        'login_id' => 'NOTIFYBP2',
        'email' => 'bp2-submit@example.com',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($childUser, 'bp_owner', RoleScope::Bp, $bp2->id);

    $admin = User::factory()->admin()->create([
        'login_id' => 'NOTIFYCTRADMIN',
        'password' => 'Password123!',
        'email' => 'admin-ctr@example.com',
    ]);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $bp2->id,
        'name' => 'Notify Contract Customer',
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

    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '通知テスト品目',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);

    $contracts = app(ContractService::class);
    $contract = $contracts->createDraft($childUser, $site, [$item->id]);
    $contracts->submitPriceApproval($childUser, $contract->fresh());

    Notification::assertSentTo($parentUser, ContractApplicationNotification::class);
    Notification::assertNotSentTo($childUser, ContractApplicationNotification::class);
    Notification::assertNotSentTo($admin, ContractApplicationNotification::class);
});

it('rejects price approval submit by parent bp on child contract', function () {
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $bp1 = $hierarchy->createRoot($seq->next(PartnerCodePrefix::Bpn), 'Reject BP1');
    $bp2 = $hierarchy->createChild($bp1, $seq->next(PartnerCodePrefix::Bpn), 'Reject BP2');

    $parentUser = User::factory()->bp($bp1)->create(['login_id' => 'REJBP1', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($parentUser, 'bp_owner', RoleScope::Bp, $bp1->id);
    $childUser = User::factory()->bp($bp2)->create(['login_id' => 'REJBP2', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($childUser, 'bp_owner', RoleScope::Bp, $bp2->id);

    $admin = User::factory()->admin()->create(['login_id' => 'REJADMIN', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

    $customer = Customer::query()->create([
        'code' => $seq->next(PartnerCodePrefix::Cn),
        'managing_bp_id' => $bp2->id,
        'name' => 'Reject Customer',
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
    $item = app(CatalogPricingService::class)->createItem($admin, [
        'name' => '却下テスト品目',
        'billing_type' => BillingType::Initial->value,
        'partition_price' => 1000,
        'user_price' => 2000,
    ]);

    $contracts = app(ContractService::class);
    $contract = $contracts->createDraft($childUser, $site, [$item->id]);

    expect(fn () => $contracts->submitPriceApproval($parentUser, $contract->fresh()))
        ->toThrow(InvalidArgumentException::class, '価格承認申請は契約の管理BPのみ行えます。');
});
