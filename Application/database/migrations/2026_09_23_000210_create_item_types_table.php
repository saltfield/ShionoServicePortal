<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('owning_bp_id')->nullable()->constrained('business_partners')->nullOnDelete();
            $table->timestamps();

            $table->index(['owning_bp_id', 'is_active']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('item_type_id')->nullable()->after('billing_type')->constrained('item_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_type_id');
        });
        Schema::dropIfExists('item_types');
    }
};
