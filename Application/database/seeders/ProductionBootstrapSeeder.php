<?php

namespace Database\Seeders;

use App\Domains\Auth\Enums\TwoFactorMode;
use App\Domains\Auth\Enums\UserType;
use App\Domains\Iam\Enums\RoleScope;
use App\Domains\Iam\Services\RbacService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * 本番初回向け: IAM/ABAC 等のマスタ＋管理者1名のみ（BP/カスタマーデモは作らない）。
 * Faker 不要のため composer --no-dev でも実行できる。
 */
class ProductionBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            IamSeeder::class,
            AbacSeeder::class,
            DataFieldNameSeeder::class,
        ]);

        $loginId = strtoupper(trim((string) env('ADMIN_SEED_LOGIN_ID', 'ADMIN001')));
        $password = (string) env('ADMIN_SEED_PASSWORD', '');
        $name = trim((string) env('ADMIN_SEED_NAME', '管理者'));
        $email = trim((string) env('ADMIN_SEED_EMAIL', ''));

        if ($loginId === '') {
            throw new RuntimeException('ADMIN_SEED_LOGIN_ID が空です。Application/.env を確認してください。');
        }

        if ($password === '') {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD を Application/.env に設定してから再実行してください（本番初期管理者のパスワード）。'
            );
        }

        if (strlen($password) < 10) {
            throw new RuntimeException('ADMIN_SEED_PASSWORD は10文字以上にしてください。');
        }

        $existing = User::query()
            ->where('user_type', UserType::Admin->value)
            ->where('login_id', $loginId)
            ->first();

        if ($existing !== null) {
            $this->command?->warn("管理者 {$loginId} は既に存在するためスキップしました。");

            return;
        }

        $admin = User::query()->create([
            'login_id' => $loginId,
            'password' => Hash::make($password),
            'user_type' => UserType::Admin,
            'bp_id' => null,
            'customer_id' => null,
            'name' => $name !== '' ? $name : '管理者',
            'email' => $email !== '' ? $email : null,
            'two_factor_mode' => TwoFactorMode::Optional,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        app(RbacService::class)->assignRole($admin, 'system_admin', RoleScope::System);

        $this->command?->info('本番用管理者を作成しました。');
        $this->command?->table(
            ['項目', '値'],
            [
                ['ログインURL', '/admin/login'],
                ['ログインID', $loginId],
                ['パスワード', '（ADMIN_SEED_PASSWORD で設定した値）'],
                ['初回', 'パスワード変更が要求されます'],
                ['ロール', 'system_admin'],
            ]
        );
        $this->command?->warn('シード後は Application/.env から ADMIN_SEED_PASSWORD を削除することを推奨します。');
    }
}
