<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('special_price_requested')->default(false)->after('status');
            $table->text('special_price_reason')->nullable()->after('special_price_requested');
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->unsignedBigInteger('standard_partition_price')->nullable()->after('partition_price');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['special_price_requested', 'special_price_reason']);
        });
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropColumn('standard_partition_price');
        });
    }
};
