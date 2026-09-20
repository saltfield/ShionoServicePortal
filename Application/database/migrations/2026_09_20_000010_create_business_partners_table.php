<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('business_partners')->nullOnDelete();
            $table->unsignedTinyInteger('depth')->default(1);
            $table->enum('two_factor_mode', ['forced', 'optional', 'disabled'])->default('optional');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_partners');
    }
};
