<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class IamSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['code' => 'iam.user.manage', 'name' => 'ユーザー管理', 'resource' => 'iam.user', 'action' => 'manage'],
            ['code' => 'iam.role.manage', 'name' => 'ロール管理', 'resource' => 'iam.role', 'action' => 'manage'],
            ['code' => 'iam.policy.manage', 'name' => 'ABACポリシー管理', 'resource' => 'iam.policy', 'action' => 'manage'],
            ['code' => 'bp.view', 'name' => 'BP参照', 'resource' => 'bp', 'action' => 'view'],
            ['code' => 'bp.manage', 'name' => 'BP管理', 'resource' => 'bp', 'action' => 'manage'],
            ['code' => 'customer.view', 'name' => 'カスタマー参照', 'resource' => 'customer', 'action' => 'view'],
            ['code' => 'customer.manage', 'name' => 'カスタマー管理', 'resource' => 'customer', 'action' => 'manage'],
            ['code' => 'site.manage', 'name' => '拠点管理', 'resource' => 'site', 'action' => 'manage'],
            ['code' => 'item.manage', 'name' => '品目管理', 'resource' => 'item', 'action' => 'manage'],
            ['code' => 'price.wholesale.edit', 'name' => '卸価格編集', 'resource' => 'price.wholesale', 'action' => 'edit'],
            ['code' => 'price.customer.edit', 'name' => 'カスタマー価格編集', 'resource' => 'price.customer', 'action' => 'edit'],
            ['code' => 'contract.view', 'name' => '契約参照', 'resource' => 'contract', 'action' => 'view'],
            ['code' => 'contract.create', 'name' => '契約作成', 'resource' => 'contract', 'action' => 'create'],
            ['code' => 'contract.approve', 'name' => '契約承認', 'resource' => 'contract', 'action' => 'approve'],
            ['code' => 'inquiry.view', 'name' => '問い合わせ参照', 'resource' => 'inquiry', 'action' => 'view'],
            ['code' => 'inquiry.reply', 'name' => '問い合わせ返信', 'resource' => 'inquiry', 'action' => 'reply'],
            ['code' => 'inquiry.close', 'name' => '問い合わせクローズ', 'resource' => 'inquiry', 'action' => 'close'],
            ['code' => 'inquiry.reopen', 'name' => '問い合わせ再オープン', 'resource' => 'inquiry', 'action' => 'reopen'],
            ['code' => 'announcement.manage', 'name' => 'お知らせ管理', 'resource' => 'announcement', 'action' => 'manage'],
            ['code' => 'admin.user.reset_2fa', 'name' => '2FA緊急スキップ', 'resource' => 'admin.user', 'action' => 'reset_2fa'],
            ['code' => 'admin.user.force_password', 'name' => 'パスワード強制変更', 'resource' => 'admin.user', 'action' => 'force_password'],
            ['code' => 'admin.bp.two_factor.manage', 'name' => 'BP 2FAモード管理', 'resource' => 'admin.bp', 'action' => 'two_factor_manage'],
            ['code' => 'audit.log.view', 'name' => '監査ログ参照', 'resource' => 'audit.log', 'action' => 'view'],
            ['code' => 'invoice.view', 'name' => '請求参照', 'resource' => 'invoice', 'action' => 'view'],
            ['code' => 'invoice.manage', 'name' => '請求管理', 'resource' => 'invoice', 'action' => 'manage'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission['code']],
                $permission
            );
        }

        $allCodes = collect($permissions)->pluck('code');

        $roles = [
            'system_admin' => [
                'name' => 'システム管理者',
                'scope' => 'system',
                'description' => '全権限',
                'permissions' => $allCodes->all(),
            ],
            'bp_owner' => [
                'name' => 'BP責任者',
                'scope' => 'bp',
                'description' => 'BP配下の顧客・契約・価格・問い合わせ・お知らせ',
                'permissions' => [
                    'bp.view', 'bp.manage', 'customer.view', 'customer.manage', 'site.manage',
                    'price.wholesale.edit', 'price.customer.edit',
                    'contract.view', 'contract.create', 'contract.approve',
                    'invoice.view', 'invoice.manage',
                    'inquiry.view', 'inquiry.reply', 'inquiry.close', 'inquiry.reopen',
                    'announcement.manage',
                    'iam.user.manage',
                ],
            ],
            'bp_sales' => [
                'name' => 'BP営業',
                'scope' => 'bp',
                'description' => '契約・価格（閲覧/作成中心）',
                'permissions' => [
                    'bp.view', 'customer.view', 'contract.view', 'contract.create',
                    'price.customer.edit', 'invoice.view', 'invoice.manage', 'inquiry.view',
                ],
            ],
            'bp_support' => [
                'name' => 'BPサポート',
                'scope' => 'bp',
                'description' => '問い合わせ中心',
                'permissions' => [
                    'customer.view', 'inquiry.view', 'inquiry.reply', 'inquiry.close', 'inquiry.reopen',
                ],
            ],
            'customer_owner' => [
                'name' => 'カスタマー責任者',
                'scope' => 'customer',
                'description' => '自CNのユーザー管理・契約・請求・問い合わせ',
                'permissions' => [
                    'iam.user.manage',
                    'contract.view', 'invoice.view', 'inquiry.view', 'inquiry.reply',
                ],
            ],
            'customer_member' => [
                'name' => 'カスタマーメンバー',
                'scope' => 'customer',
                'description' => '自己契約・請求閲覧、問い合わせ起票',
                'permissions' => [
                    'contract.view', 'invoice.view', 'inquiry.view', 'inquiry.reply',
                ],
            ],
        ];

        foreach ($roles as $code => $definition) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                ]
            );

            $permissionIds = Permission::query()
                ->whereIn('code', $definition['permissions'])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
