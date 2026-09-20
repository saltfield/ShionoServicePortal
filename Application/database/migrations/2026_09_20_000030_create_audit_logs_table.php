<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50); // login, privilege, authorization, activity
            $table->string('action', 100);
            $table->string('result', 30); // success, failure, allow, deny
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
