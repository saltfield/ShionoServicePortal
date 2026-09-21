<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('first_billing_year_month', 6)->nullable()->after('activated_at');
            $table->string('final_billing_year_month', 6)->nullable()->after('first_billing_year_month');
            $table->timestamp('cancelled_at')->nullable()->after('final_billing_year_month');
            $table->unsignedInteger('cancellation_amount')->nullable()->after('cancelled_at');
            $table->string('cancellation_note', 500)->nullable()->after('cancellation_amount');
            $table->unsignedSmallInteger('minimum_term_months_snapshot')->nullable()->after('cancellation_note');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('minimum_term_months')->nullable()->after('tax_rate');
        });

        Schema::create('contract_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('body', 2000);
            $table->timestamps();

            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_messages');

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('minimum_term_months');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'first_billing_year_month',
                'final_billing_year_month',
                'cancelled_at',
                'cancellation_amount',
                'cancellation_note',
                'minimum_term_months_snapshot',
            ]);
        });
    }
};
