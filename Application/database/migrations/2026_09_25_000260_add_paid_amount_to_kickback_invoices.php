<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickback_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('kickback_invoices', 'paid_amount')) {
                $table->bigInteger('paid_amount')->nullable()->after('total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kickback_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('kickback_invoices', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};
