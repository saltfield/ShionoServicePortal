<?php

use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\PolicyManagementService;
use App\Domains\Iam\Services\RbacService;
use App\Models\AuditLog;
use App\Models\Policy;
use App\Models\User;
use Database\Seeders\AbacSeeder;
use Database\Seeders\IamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IamSeeder::class);
    $this->seed(AbacSeeder::class);
});

function policyMgmtAdmin(): User
{
    $user = User::factory()->admin()->create([
        'login_id' => 'POLICYADMIN',
        'password' => 'Password123!',
        'must_change_password' => false,
    ]);
    app(RbacService::class)->assignRole($user, 'system_admin', RoleScope::System);

    return $user;
}

it('creates updates and deletes abac policies with conditions', function () {
    $admin = policyMgmtAdmin();
    $service = app(PolicyManagementService::class);

    $policy = $service->create($admin, [
        'code' => 'P10_test_allow',
        'name' => 'テスト許可',
        'effect' => 'allow',
        'resource' => 'contract',
        'action' => 'contract.view',
        'priority' => 120,
        'is_active' => true,
        'description' => 'desc',
    ], [
        ['group_no' => 1, 'attribute' => 'resource.relation', 'operator' => 'in', 'value' => 'same_bp, descendant'],
        ['group_no' => 2, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value' => 'bp'],
    ]);

    expect($policy->code)->toBe('P10_test_allow')
        ->and($policy->conditions)->toHaveCount(2)
        ->and($policy->conditions->first()->value_json)->toBe(['same_bp', 'descendant'])
        ->and(AuditLog::query()->where('action', 'policy.create')->exists())->toBeTrue();

    $service->update($admin, $policy, [
        'name' => 'テスト許可（更新）',
        'effect' => 'deny',
        'resource' => 'contract',
        'action' => 'contract.view',
        'priority' => 200,
        'is_active' => false,
        'description' => 'updated',
    ], [
        ['group_no' => 1, 'attribute' => 'resource.status', 'operator' => 'eq', 'value' => 'closed'],
    ]);

    $policy->refresh()->load('conditions');
    expect($policy->name)->toBe('テスト許可（更新）')
        ->and($policy->effect)->toBe('deny')
        ->and($policy->is_active)->toBeFalse()
        ->and($policy->conditions)->toHaveCount(1);

    $service->delete($admin, $policy);
    expect(Policy::query()->where('code', 'P10_test_allow')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'policy.delete')->exists())->toBeTrue();
});

it('manages policies via admin http ui', function () {
    $admin = policyMgmtAdmin();

    $this->post(route('admin.login.store'), [
        'login_id' => 'POLICYADMIN',
        'password' => 'Password123!',
    ]);

    $this->get(route('admin.policies.index'))
        ->assertOk()
        ->assertSee('ABACポリシー管理')
        ->assertSee('P1_contract_view_scope');

    $this->get(route('admin.policies.create'))
        ->assertOk()
        ->assertSee('ポリシー作成');

    $this->post(route('admin.policies.store'), [
        'code' => 'P99_http_policy',
        'name' => 'HTTP作成',
        'effect' => 'allow',
        'resource' => 'inquiry',
        'action' => 'inquiry.view',
        'priority' => 80,
        'is_active' => '1',
        'description' => 'http',
        'conditions' => [
            ['group_no' => 1, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value' => 'admin'],
        ],
    ])->assertRedirect();

    $policy = Policy::query()->where('code', 'P99_http_policy')->first();
    expect($policy)->not->toBeNull()
        ->and($policy->conditions)->toHaveCount(1);

    $this->get(route('admin.policies.edit', $policy))
        ->assertOk()
        ->assertSee('HTTP作成');

    $this->put(route('admin.policies.update', $policy), [
        'name' => 'HTTP更新',
        'effect' => 'allow',
        'resource' => 'inquiry',
        'action' => 'inquiry.view',
        'priority' => 90,
        'is_active' => '1',
        'conditions' => [
            ['group_no' => 1, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value' => 'bp'],
            ['group_no' => 1, 'attribute' => '', 'operator' => 'eq', 'value' => ''],
        ],
    ])->assertRedirect(route('admin.policies.edit', $policy));

    expect($policy->fresh()->name)->toBe('HTTP更新')
        ->and($policy->fresh()->conditions)->toHaveCount(1);

    $this->delete(route('admin.policies.destroy', $policy))
        ->assertRedirect(route('admin.policies.index'));

    expect(Policy::query()->where('code', 'P99_http_policy')->exists())->toBeFalse();
});
