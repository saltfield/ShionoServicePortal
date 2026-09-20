<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->string('postal_code', 8)->nullable()->after('name');
            $table->string('address')->nullable()->after('postal_code');
            $table->string('building_name')->nullable()->after('address');
            $table->string('phone', 20)->nullable()->after('building_name');
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropColumn(['postal_code', 'address', 'building_name', 'phone', 'email']);
        });
    }
};
