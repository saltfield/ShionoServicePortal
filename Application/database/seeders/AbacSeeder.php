<?php

namespace Database\Seeders;

use App\Models\Policy;
use Illuminate\Database\Seeder;

class AbacSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPolicy(
            code: 'P1_contract_view_scope',
            name: '契約閲覧は自BPまたは配下のみ',
            effect: 'allow',
            resource: 'contract',
            action: 'contract.view',
            priority: 100,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.relation', 'operator' => 'in', 'value_json' => ['same_bp', 'descendant']],
            ],
        );

        $this->seedPolicy(
            code: 'P1b_contract_create_scope',
            name: '契約作成は自BPまたは配下のみ',
            effect: 'allow',
            resource: 'contract',
            action: 'contract.create',
            priority: 100,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.relation', 'operator' => 'in', 'value_json' => ['same_bp', 'descendant']],
            ],
        );

        $this->seedPolicy(
            code: 'P1c_contract_approve_scope',
            name: '契約承認は申請元が配下の場合',
            effect: 'allow',
            resource: 'contract',
            action: 'contract.approve',
            priority: 100,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.relation', 'operator' => 'in', 'value_json' => ['descendant']],
            ],
        );

        $this->seedPolicy(
            code: 'P2_wholesale_direct_child',
            name: '卸価格編集は直接の子のみ',
            effect: 'allow',
            resource: 'price',
            action: 'price.wholesale.edit',
            priority: 100,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.relation', 'operator' => 'eq', 'value_json' => 'descendant'],
                ['group_no' => 2, 'attribute' => 'resource.depth_diff', 'operator' => 'eq', 'value_json' => 1],
            ],
        );

        $this->seedPolicy(
            code: 'P3_high_amount_deny_parent_only',
            name: '高額承認は直近親のみでは不可',
            effect: 'deny',
            resource: 'contract',
            action: 'contract.approve',
            priority: 200,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.amount', 'operator' => 'gte', 'value_json' => 1000000],
                ['group_no' => 2, 'attribute' => 'subject.depth_diff_to_applicant', 'operator' => 'eq', 'value_json' => 1],
            ],
        );

        $this->seedPolicy(
            code: 'P4_closed_inquiry_deny_reply',
            name: 'クローズ済みチケットへの返信を拒否（reopen権限者以外）',
            effect: 'deny',
            resource: 'inquiry',
            action: 'inquiry.reply',
            priority: 200,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.status', 'operator' => 'eq', 'value_json' => 'closed'],
                ['group_no' => 2, 'attribute' => 'subject.permission_codes', 'operator' => 'not_contains', 'value_json' => 'inquiry.reopen'],
            ],
        );

        $this->seedPolicy(
            code: 'P4b_withdrawn_inquiry_deny_reply',
            name: '取下げ済みチケットへの返信を拒否',
            effect: 'deny',
            resource: 'inquiry',
            action: 'inquiry.reply',
            priority: 200,
            conditions: [
                ['group_no' => 1, 'attribute' => 'resource.status', 'operator' => 'eq', 'value_json' => 'withdrawn'],
            ],
        );

        $this->seedPolicy(
            code: 'P5_admin_reset_2fa',
            name: '2FA緊急スキップは管理者のみ',
            effect: 'allow',
            resource: '*',
            action: 'admin.user.reset_2fa',
            priority: 100,
            conditions: [
                ['group_no' => 1, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value_json' => 'admin'],
            ],
        );

        $this->seedPolicy(
            code: 'P6_admin_wholesale_edit',
            name: '卸価格編集は管理者も可',
            effect: 'allow',
            resource: 'price',
            action: 'price.wholesale.edit',
            priority: 50,
            conditions: [
                ['group_no' => 1, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value_json' => 'admin'],
            ],
        );

        foreach (['contract.view', 'contract.create', 'contract.approve'] as $index => $action) {
            $this->seedPolicy(
                code: 'P7_admin_'.$action,
                name: "管理者の{$action}",
                effect: 'allow',
                resource: 'contract',
                action: $action,
                priority: 50,
                conditions: [
                    ['group_no' => 1, 'attribute' => 'subject.user_type', 'operator' => 'eq', 'value_json' => 'admin'],
                ],
            );
        }
    }

    /**
     * @param  list<array{group_no:int, attribute:string, operator:string, value_json:mixed}>  $conditions
     */
    private function seedPolicy(
        string $code,
        string $name,
        string $effect,
        string $resource,
        string $action,
        int $priority,
        array $conditions,
    ): void {
        /** @var Policy $policy */
        $policy = Policy::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'effect' => $effect,
                'resource' => $resource,
                'action' => $action,
                'priority' => $priority,
                'is_active' => true,
                'description' => $name,
            ]
        );

        $policy->conditions()->delete();

        foreach ($conditions as $condition) {
            $policy->conditions()->create($condition);
        }
    }
}
