<?php

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\BusinessPartner;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function bpAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'BPADMIN',
        'password' => 'Password123!',
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('allows admin to create root and child bp via http', function () {
    bpAdmin();

    $this->post(route('admin.login.store'), [
        'login_id' => 'BPADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Root Co',
        'two_factor_mode' => TwoFactorMode::Optional->value,
        'is_active' => '1',
    ])->assertRedirect();

    $root = BusinessPartner::query()->where('name', 'Root Co')->first();
    expect($root)->not->toBeNull()->and($root->depth)->toBe(1);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'Child Co',
        'parent_id' => $root->id,
        'two_factor_mode' => TwoFactorMode::Forced->value,
        'is_active' => '1',
    ])->assertRedirect();

    $child = BusinessPartner::query()->where('name', 'Child Co')->first();
    expect($child->parent_id)->toBe($root->id)
        ->and($child->two_factor_mode)->toBe(TwoFactorMode::Forced)
        ->and(AuditLog::query()->where('action', 'bp.create')->count())->toBe(2);
});

it('updates and moves bp from admin screens', function () {
    bpAdmin();
    $this->post(route('admin.login.store'), [
        'login_id' => 'BPADMIN',
        'password' => 'Password123!',
    ]);

    $this->post(route('admin.business-partners.store'), [
        'name' => 'A',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);
    $this->post(route('admin.business-partners.store'), [
        'name' => 'B',
        'two_factor_mode' => 'optional',
        'is_active' => '1',
    ]);

    $a = BusinessPartner::query()->where('name', 'A')->first();
    $b = BusinessPartner::query()->where('name', 'B')->first();

    $this->put(route('admin.business-partners.update', $a), [
        'name' => 'A Updated',
        'two_factor_mode' => 'disabled',
        'is_active' => '1',
    ])->assertRedirect();

    expect($a->fresh()->name)->toBe('A Updated')
        ->and($a->fresh()->two_factor_mode)->toBe(TwoFactorMode::Disabled);

    $this->get(route('admin.business-partners.show', $a))->assertOk();
    $moveCode = session('bp_move_confirm.'.$a->id);

    $this->from(route('admin.business-partners.show', $a))
        ->put(route('admin.business-partners.move', $a), [
            'parent_id' => $b->id,
            'confirmation_code' => '0000',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('confirmation_code');

    expect($a->fresh()->parent_id)->toBeNull();

    $this->get(route('admin.business-partners.show', $a));
    $moveCode = session('bp_move_confirm.'.$a->id);

    $this->put(route('admin.business-partners.move', $a), [
        'parent_id' => $b->id,
        'confirmation_code' => $moveCode,
    ])->assertRedirect();

    expect($a->fresh()->parent_id)->toBe($b->id)
        ->and(AuditLog::query()->where('action', 'bp.move')->exists())->toBeTrue();
});
