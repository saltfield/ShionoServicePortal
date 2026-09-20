<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedTinyInteger('tax_rate')->default(10)->after('user_price');
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('tax_rate')->default(10)->after('partition_price');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
