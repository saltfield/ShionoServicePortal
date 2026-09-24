<?php

use Database\Seeders\DataFieldNameSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DataFieldNameSeeder)->run();
    }

    public function down(): void
    {
        // 初期マスタは残す（運用中の契約データ参照を壊さない）
    }
};
