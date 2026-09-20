<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 8)->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->char('year_month', 6)->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
