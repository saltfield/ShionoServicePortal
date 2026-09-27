<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('visibility', 32); // shared | organization
            $table->string('owner_type', 32)->default('_'); // _ | admin | bp | customer
            $table->unsignedBigInteger('owner_id')->default(0);
            $table->longText('body')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'visibility', 'owner_type', 'owner_id'], 'contract_notes_scope_unique');
            $table->index(['contract_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_notes');
    }
};
