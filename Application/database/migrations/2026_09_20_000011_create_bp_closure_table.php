<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bp_closure', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('business_partners')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('business_partners')->cascadeOnDelete();
            $table->unsignedTinyInteger('depth_diff');

            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id', 'ancestor_id']);
            $table->index(['ancestor_id', 'depth_diff']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_closure');
    }
};
