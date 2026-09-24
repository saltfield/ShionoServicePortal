<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('billing_batch_run_id')
                ->nullable()
                ->after('source')
                ->constrained('billing_batch_runs')
                ->nullOnDelete();
            $table->index(['billing_batch_run_id']);
        });

        Schema::table('kickback_invoices', function (Blueprint $table) {
            $table->foreignId('billing_batch_run_id')
                ->nullable()
                ->after('contract_id')
                ->constrained('billing_batch_runs')
                ->nullOnDelete();
            $table->index(['billing_batch_run_id']);
        });

        // 既存の自動生成分を実行ウィンドウで紐付け（可能な範囲）
        $runs = DB::table('billing_batch_runs')->orderBy('id')->get();
        foreach ($runs as $run) {
            $from = $run->started_at;
            $to = $run->finished_at ?: $run->started_at;

            DB::table('invoices')
                ->where('billing_year_month', $run->billing_year_month)
                ->where('source', 'auto')
                ->whereNull('billing_batch_run_id')
                ->whereBetween('created_at', [$from, $to])
                ->update(['billing_batch_run_id' => $run->id]);

            DB::table('kickback_invoices')
                ->where('billing_year_month', $run->billing_year_month)
                ->whereNull('billing_batch_run_id')
                ->whereBetween('created_at', [$from, $to])
                ->update(['billing_batch_run_id' => $run->id]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_batch_run_id');
        });
        Schema::table('kickback_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_batch_run_id');
        });
    }
};
