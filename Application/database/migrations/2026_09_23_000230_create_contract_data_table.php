<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('data_field_name_id')->nullable()->constrained('data_field_names')->nullOnDelete();
            $table->string('name');
            $table->string('replace_code', 64)->nullable();
            $table->string('value', 1000);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_data');
    }
};
