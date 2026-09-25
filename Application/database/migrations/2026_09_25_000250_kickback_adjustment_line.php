<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickback_invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('kickback_invoice_lines', 'is_adjustment')) {
                $table->boolean('is_adjustment')->default(false)->after('sort_order');
            }
        });

        // 端数調整（マイナス）を明細登録できるように符号付きへ変更
        DB::statement('ALTER TABLE kickback_invoice_lines MODIFY upper_amount BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoice_lines MODIFY partition_amount BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoice_lines MODIFY amount BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoice_lines MODIFY tax_amount BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoice_lines MODIFY amount_inclusive BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoices MODIFY subtotal BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoices MODIFY tax_total BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE kickback_invoices MODIFY total BIGINT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        Schema::table('kickback_invoice_lines', function (Blueprint $table) {
            if (Schema::hasColumn('kickback_invoice_lines', 'is_adjustment')) {
                $table->dropColumn('is_adjustment');
            }
        });
    }
};
