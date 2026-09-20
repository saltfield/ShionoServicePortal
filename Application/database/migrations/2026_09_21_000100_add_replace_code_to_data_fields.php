<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_field_names', function (Blueprint $table) {
            $table->string('replace_code', 64)->nullable()->after('name');
            $table->unique('replace_code');
        });

        Schema::table('contract_item_data', function (Blueprint $table) {
            $table->string('replace_code', 64)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('contract_item_data', function (Blueprint $table) {
            $table->dropColumn('replace_code');
        });

        Schema::table('data_field_names', function (Blueprint $table) {
            $table->dropUnique(['replace_code']);
            $table->dropColumn('replace_code');
        });
    }
};
