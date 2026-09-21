<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('published_at');
            $table->boolean('include_new_registrations')->default(true)->after('expires_at');
            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['published_at', 'expires_at']);
            $table->dropColumn(['expires_at', 'include_new_registrations']);
        });
    }
};
