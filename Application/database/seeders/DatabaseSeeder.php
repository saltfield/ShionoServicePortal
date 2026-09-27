<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 開発用（デモ BP/カスタマー含む。Faker が必要 = composer --no-dev では不可）
        // 本番初回は: php artisan db:seed --class=Database\\Seeders\\ProductionBootstrapSeeder --force
        $this->call([
            IamSeeder::class,
            AbacSeeder::class,
            DataFieldNameSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
