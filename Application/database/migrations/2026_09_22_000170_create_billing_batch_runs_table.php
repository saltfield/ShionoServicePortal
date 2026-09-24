<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('billing_year_month', 6);
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('invoices_count')->default(0);
            $table->unsignedInteger('kickbacks_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['billing_year_month', 'created_at']);
        });

        Schema::table('billing_batch_errors', function (Blueprint $table) {
            $table->foreignId('billing_batch_run_id')
                ->nullable()
                ->after('id')
                ->constrained('billing_batch_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('billing_batch_errors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_batch_run_id');
        });
        Schema::dropIfExists('billing_batch_runs');
    }
};
