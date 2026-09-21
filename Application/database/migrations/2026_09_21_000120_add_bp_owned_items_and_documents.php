<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('owning_bp_id')
                ->nullable()
                ->after('is_active')
                ->constrained('business_partners')
                ->nullOnDelete();
            $table->index('owning_bp_id');
        });

        Schema::table('item_documents', function (Blueprint $table) {
            $table->foreignId('owning_bp_id')
                ->nullable()
                ->after('item_id')
                ->constrained('business_partners')
                ->nullOnDelete();
            $table->index(['item_id', 'owning_bp_id']);
        });
    }

    public function down(): void
    {
        Schema::table('item_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owning_bp_id');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owning_bp_id');
        });
    }
};
