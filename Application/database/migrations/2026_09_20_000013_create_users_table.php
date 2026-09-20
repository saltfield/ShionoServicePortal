<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('login_id', 64)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->string('password');
            $table->enum('user_type', ['admin', 'bp', 'customer']);
            $table->foreignId('bp_id')->nullable()->constrained('business_partners')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->enum('two_factor_mode', ['forced', 'optional', 'disabled'])->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_forced_disabled')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
