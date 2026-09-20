<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('building_name', 255)->nullable()->after('address');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->string('building_name', 255)->nullable()->after('address');
            $table->string('billing_building_name', 255)->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('building_name');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['building_name', 'billing_building_name']);
        });
    }
};
