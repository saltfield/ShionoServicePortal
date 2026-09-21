<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('users'));

            if ($indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_user_type_login_id_unique')) {
                $table->dropUnique(['user_type', 'login_id']);
            }

            if (! $indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_bp_id_login_id_unique')) {
                $table->unique(['bp_id', 'login_id']);
            }

            if (! $indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_customer_id_login_id_unique')) {
                $table->unique(['customer_id', 'login_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('users'));

            if ($indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_bp_id_login_id_unique')) {
                $table->dropUnique(['bp_id', 'login_id']);
            }

            if ($indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_customer_id_login_id_unique')) {
                $table->dropUnique(['customer_id', 'login_id']);
            }

            if (! $indexes->contains(fn (array $index) => ($index['name'] ?? '') === 'users_user_type_login_id_unique')) {
                $table->unique(['user_type', 'login_id']);
            }
        });
    }
};
