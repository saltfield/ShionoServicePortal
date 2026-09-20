<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->enum('effect', ['allow', 'deny']);
            $table->string('resource', 100)->default('*');
            $table->string('action', 100)->default('*');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'action', 'resource']);
        });

        Schema::create('policy_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->unsignedInteger('group_no')->default(1);
            $table->string('attribute', 100);
            $table->string('operator', 20);
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->index(['policy_id', 'group_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_conditions');
        Schema::dropIfExists('policies');
    }
};
