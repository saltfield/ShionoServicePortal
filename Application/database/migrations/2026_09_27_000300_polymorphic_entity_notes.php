<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_notes', function (Blueprint $table) {
            $table->string('subject_type', 64)->nullable()->after('id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
        });

        DB::table('contract_notes')->update([
            'subject_type' => 'contract',
            'subject_id' => DB::raw('contract_id'),
        ]);

        Schema::table('contract_notes', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropUnique('contract_notes_scope_unique');
            $table->dropIndex(['contract_id', 'visibility']);
            $table->dropColumn('contract_id');
        });

        DB::statement('ALTER TABLE contract_notes MODIFY subject_type VARCHAR(64) NOT NULL');
        DB::statement('ALTER TABLE contract_notes MODIFY subject_id BIGINT UNSIGNED NOT NULL');

        Schema::table('contract_notes', function (Blueprint $table) {
            $table->unique(
                ['subject_type', 'subject_id', 'visibility', 'owner_type', 'owner_id'],
                'entity_notes_scope_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'entity_notes_subject_index');
        });

        Schema::rename('contract_notes', 'entity_notes');
    }

    public function down(): void
    {
        Schema::rename('entity_notes', 'contract_notes');

        Schema::table('contract_notes', function (Blueprint $table) {
            $table->dropUnique('entity_notes_scope_unique');
            $table->dropIndex('entity_notes_subject_index');
            $table->unsignedBigInteger('contract_id')->nullable()->after('id');
        });

        DB::table('contract_notes')
            ->where('subject_type', 'contract')
            ->update(['contract_id' => DB::raw('subject_id')]);

        Schema::table('contract_notes', function (Blueprint $table) {
            $table->dropColumn(['subject_type', 'subject_id']);
            $table->foreign('contract_id')->references('id')->on('contracts')->cascadeOnDelete();
            $table->unique(['contract_id', 'visibility', 'owner_type', 'owner_id'], 'contract_notes_scope_unique');
            $table->index(['contract_id', 'visibility']);
        });
    }
};
