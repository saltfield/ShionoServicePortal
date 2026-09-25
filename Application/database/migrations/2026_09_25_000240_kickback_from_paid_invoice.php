<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->unsignedBigInteger('paid_amount')->nullable()->after('total');
            }
        });

        Schema::table('kickback_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('kickback_invoices', 'source_invoice_id')) {
                $table->foreignId('source_invoice_id')
                    ->nullable()
                    ->after('contract_id')
                    ->constrained('invoices')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('kickback_invoices', 'manual_adjusted')) {
                $table->boolean('manual_adjusted')->default(false)->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kickback_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('kickback_invoices', 'manual_adjusted')) {
                $table->dropColumn('manual_adjusted');
            }
            if (Schema::hasColumn('kickback_invoices', 'source_invoice_id')) {
                $table->dropConstrainedForeignId('source_invoice_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};
