<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->foreignId('managing_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->string('name');
            $table->string('name_kana')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->enum('entity_type', ['individual', 'corporate'])->default('corporate');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
