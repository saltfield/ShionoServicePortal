<?php

namespace Database\Seeders;

use App\Domains\Auth\Enums\PartnerCodePrefix;
use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Services\BpHierarchyService;
use App\Domains\Auth\Services\NumberSequenceService;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(IamSeeder::class);
        $this->call(AbacSeeder::class);

        User::query()->whereIn('login_id', ['ADMIN001', 'BPUSER001', 'CUSUSER001'])->forceDelete();

        $admin = User::factory()->admin()->create([
            'login_id' => 'ADMIN001',
            'password' => 'Password123!',
            'name' => 'デモ管理者',
            'email' => 'admin@example.com',
            'two_factor_mode' => TwoFactorMode::Optional,
            'is_active' => true,
        ]);

        $sequences = app(NumberSequenceService::class);
        $hierarchy = app(BpHierarchyService::class);
        $rbac = app(RbacService::class);

        // Prefer stable demo codes when sequences are empty; otherwise issue next.
        $bpn = 'BPN202609001';
        if (\App\Models\BusinessPartner::query()->where('code', $bpn)->exists()) {
            $bpn = $sequences->next(PartnerCodePrefix::Bpn);
        } else {
            // Reserve sequence so subsequent issues stay consistent.
            \App\Models\NumberSequence::query()->firstOrCreate(
                ['prefix' => 'BPN', 'year_month' => '202609'],
                ['last_seq' => 1]
            );
        }

        $partner = $hierarchy->createRoot($bpn, 'デモBP株式会社', [
            'two_factor_mode' => TwoFactorMode::Optional,
        ]);

        $bpUser = User::factory()->bp($partner)->create([
            'login_id' => 'BPUSER001',
            'password' => 'Password123!',
            'name' => 'デモBPユーザー',
            'email' => 'bp@example.com',
            'is_active' => true,
        ]);

        $cn = 'CN202609001';
        if (Customer::query()->where('code', $cn)->exists()) {
            $cn = $sequences->next(PartnerCodePrefix::Cn);
        } else {
            \App\Models\NumberSequence::query()->firstOrCreate(
                ['prefix' => 'CN', 'year_month' => '202609'],
                ['last_seq' => 1]
            );
        }

        $customer = Customer::factory()->create([
            'code' => $cn,
            'managing_bp_id' => $partner->id,
            'name' => 'デモカスタマー株式会社',
        ]);

        $customerUser = User::factory()->customer($customer)->create([
            'login_id' => 'CUSUSER001',
            'password' => 'Password123!',
            'name' => 'デモカスタマーユーザー',
            'email' => 'customer@example.com',
            'is_active' => true,
        ]);

        $rbac->assignRole($admin, 'system_admin', RoleScope::System);
        $rbac->assignRole($bpUser, 'bp_owner', RoleScope::Bp, $partner->id);
        $rbac->assignRole($customerUser, 'customer_owner', RoleScope::Customer, $customer->id);

        $this->command?->info('Demo users ready.');
        $this->command?->table(
            ['種別', 'URL', 'ログインID', 'BPN/CN', 'パスワード', 'ロール'],
            [
                ['管理者', '/admin/login', 'ADMIN001', '（不要）', 'Password123!', 'system_admin'],
                ['BP', '/bp/login', 'BPUSER001', $partner->code, 'Password123!', 'bp_owner'],
                ['カスタマー', '/customer/login', 'CUSUSER001', $customer->code, 'Password123!', 'customer_owner'],
            ]
        );
    }
}
