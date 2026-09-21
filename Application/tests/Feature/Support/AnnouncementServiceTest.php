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
use Illuminate\Support\Carbon;

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

it('publishes multi bp targets and all-bp broadcast kinds', function () {
    $fx = announcementFixture();
    $service = app(AnnouncementService::class);
    $seq = app(NumberSequenceService::class);
    $hierarchy = app(BpHierarchyService::class);
    $child = $hierarchy->createChild($fx['bp'], $seq->next(PartnerCodePrefix::Bpn), 'Child BP');
    $childUser = User::factory()->bp($child)->create(['login_id' => 'ANNCHILD', 'password' => 'Password123!']);
    app(RbacService::class)->assignRole($childUser, 'bp_owner', RoleScope::Bp, $child->id);

    $multi = $service->publish($fx['admin'], '複数BP', '本文', [
        ['type' => AnnouncementTargetType::Bp->value, 'id' => $fx['bp']->id],
        ['type' => AnnouncementTargetType::Bp->value, 'id' => $child->id],
    ]);
    expect($multi->targets)->toHaveCount(2)
        ->and($service->isVisibleTo($fx['bpUser'], $multi))->toBeTrue()
        ->and($service->isVisibleTo($childUser, $multi))->toBeTrue()
        ->and($service->isVisibleTo($fx['customerUser'], $multi))->toBeTrue();

    $allBp = $service->publish($fx['admin'], '全BP', '本文', [
        ['type' => AnnouncementTargetType::AllBp->value],
    ], ['include_new_registrations' => true]);
    expect($allBp->targets)->toHaveCount(1)
        ->and($allBp->targets->first()->target_type)->toBe(AnnouncementTargetType::AllBp)
        ->and($service->isVisibleTo($fx['bpUser'], $allBp))->toBeTrue()
        ->and($service->isVisibleTo($fx['customerUser'], $allBp))->toBeFalse();
});

it('hides announcements outside publish window', function () {
    $fx = announcementFixture();
    $service = app(AnnouncementService::class);

    $future = $service->publish($fx['admin'], '予約', '本文', [
        ['type' => AnnouncementTargetType::All->value],
    ], [
        'published_at' => now()->addDay(),
        'include_new_registrations' => true,
    ]);
    expect($service->isVisibleTo($fx['bpUser'], $future))->toBeFalse();

    $expired = $service->publish($fx['admin'], '期限切れ', '本文', [
        ['type' => AnnouncementTargetType::AllCustomer->value],
    ], [
        'published_at' => now()->subDays(2),
        'expires_at' => now()->subDay(),
        'include_new_registrations' => true,
    ]);
    expect($service->isVisibleTo($fx['customerUser'], $expired))->toBeFalse();

    $active = $service->publish($fx['admin'], '期間内', '本文', [
        ['type' => AnnouncementTargetType::AllCustomer->value],
    ], [
        'published_at' => now()->subHour(),
        'expires_at' => now()->addDay(),
        'include_new_registrations' => true,
    ]);
    expect($service->isVisibleTo($fx['customerUser'], $active))->toBeTrue()
        ->and($service->isVisibleTo($fx['bpUser'], $active))->toBeFalse();
});

it('snapshots broadcast targets when include_new_registrations is false', function () {
    $fx = announcementFixture();
    $service = app(AnnouncementService::class);

    $announcement = $service->publish($fx['admin'], '固定', '本文', [
        ['type' => AnnouncementTargetType::AllBp->value],
    ], ['include_new_registrations' => false]);

    expect($announcement->include_new_registrations)->toBeFalse()
        ->and($announcement->targets)->toHaveCount(1)
        ->and($announcement->targets->first()->target_type)->toBe(AnnouncementTargetType::Bp)
        ->and($announcement->targets->first()->target_id)->toBe($fx['bp']->id);
});

it('allows admin http create edit with schedule and multi targets', function () {
    $fx = announcementFixture();
    $this->post(route('admin.login.store'), [
        'login_id' => 'ANNADMIN',
        'password' => 'Password123!',
    ])->assertRedirect(route('admin.dashboard'));

    $this->get(route('admin.announcements.create'))
        ->assertOk()
        ->assertSee('お知らせ登録')
        ->assertSee('全BPと全カスタマー')
        ->assertSee('配信先一覧');

    $start = Carbon::now()->addHour()->timezone(config('app.timezone'));
    $end = Carbon::now()->addDays(3)->timezone(config('app.timezone'));

    $this->post(route('admin.announcements.store'), [
        'title' => 'HTTPお知らせ',
        'body' => '本文です',
        'delivery_type' => 'bp',
        'target_ids' => [$fx['bp']->id],
        'published_mode' => 'scheduled',
        'published_date' => $start->format('Y-m-d'),
        'published_time' => $start->format('H:i'),
        'expires_mode' => 'until',
        'expires_date' => $end->format('Y-m-d'),
        'expires_time' => $end->format('H:i'),
    ])->assertRedirect();

    $created = \App\Models\Announcement::query()->where('title', 'HTTPお知らせ')->first();
    expect($created)->not->toBeNull()
        ->and($created->expires_at)->not->toBeNull();

    $this->get(route('admin.announcements.edit', $created))
        ->assertOk()
        ->assertSee('お知らせ編集')
        ->assertSee('HTTPお知らせ');

    $this->put(route('admin.announcements.update', $created), [
        'title' => 'HTTPお知らせ更新',
        'body' => '更新本文',
        'delivery_type' => 'all_customer',
        'include_new_registrations' => '1',
        'published_mode' => 'immediate',
        'expires_mode' => 'indefinite',
    ])->assertRedirect(route('admin.announcements.show', $created));

    expect($created->fresh()->title)->toBe('HTTPお知らせ更新')
        ->and($created->fresh()->expires_at)->toBeNull()
        ->and($created->fresh()->targets->first()->target_type)->toBe(AnnouncementTargetType::AllCustomer);
});
