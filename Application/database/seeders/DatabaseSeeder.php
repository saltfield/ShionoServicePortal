<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            IamSeeder::class,
            AbacSeeder::class,
            DataFieldNameSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
