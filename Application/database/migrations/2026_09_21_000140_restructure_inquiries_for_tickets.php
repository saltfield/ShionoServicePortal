<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inquiry_message_attachments');
        Schema::dropIfExists('inquiry_reads');

        if (Schema::hasTable('inquiry_messages')) {
            DB::table('inquiry_messages')->delete();
        }
        if (Schema::hasTable('inquiries')) {
            DB::table('inquiries')->delete();
        }

        Schema::table('inquiries', function (Blueprint $table) {
            if (Schema::hasColumn('inquiries', 'owning_bp_id')) {
                $table->dropForeign(['owning_bp_id']);
                try {
                    $table->dropIndex('inquiries_owning_bp_id_status_updated_at_index');
                } catch (\Throwable) {
                    // Column drop may already remove the composite index.
                }
                $table->dropColumn('owning_bp_id');
            }
        });

        Schema::table('inquiries', function (Blueprint $table) {
            if (! Schema::hasColumn('inquiries', 'code')) {
                $table->string('code', 32)->after('id');
            }
            if (! Schema::hasColumn('inquiries', 'assignee_type')) {
                $table->string('assignee_type', 16)->after('code');
            }
            if (! Schema::hasColumn('inquiries', 'assignee_bp_id')) {
                $table->foreignId('assignee_bp_id')->nullable()->after('assignee_type')
                    ->constrained('business_partners')->nullOnDelete();
            }
            if (! Schema::hasColumn('inquiries', 'issuer_bp_id')) {
                $table->foreignId('issuer_bp_id')->nullable()->after('opened_by_user_id')
                    ->constrained('business_partners')->nullOnDelete();
            }
            if (! Schema::hasColumn('inquiries', 'visibility')) {
                $table->string('visibility', 32)->default('organization')->after('status');
            }
        });

        $this->ensureUniqueIndex('inquiries', 'inquiries_code_unique', ['code']);
        $this->ensureIndex('inquiries', 'inquiries_assignee_type_assignee_bp_id_status_index', ['assignee_type', 'assignee_bp_id', 'status']);
        $this->ensureIndex('inquiries', 'inquiries_issuer_bp_id_status_index', ['issuer_bp_id', 'status']);
        $this->ensureIndex('inquiries', 'inquiries_customer_id_status_index', ['customer_id', 'status']);

        Schema::table('inquiry_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('inquiry_messages', 'message_type')) {
                $table->string('message_type', 32)->default('user')->after('user_id');
            }
            if (! Schema::hasColumn('inquiry_messages', 'from_status')) {
                $table->string('from_status', 32)->nullable()->after('body');
            }
            if (! Schema::hasColumn('inquiry_messages', 'to_status')) {
                $table->string('to_status', 32)->nullable()->after('from_status');
            }
        });

        Schema::create('inquiry_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_message_id')->constrained('inquiry_messages')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->index(['inquiry_message_id']);
        });

        Schema::create('inquiry_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['inquiry_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_reads');
        Schema::dropIfExists('inquiry_message_attachments');

        Schema::table('inquiry_messages', function (Blueprint $table) {
            if (Schema::hasColumn('inquiry_messages', 'message_type')) {
                $table->dropColumn(['message_type', 'from_status', 'to_status']);
            }
        });

        Schema::table('inquiries', function (Blueprint $table) {
            if (Schema::hasColumn('inquiries', 'code')) {
                try {
                    $table->dropUnique(['code']);
                } catch (\Throwable) {
                }
            }
            foreach ([
                'inquiries_assignee_type_assignee_bp_id_status_index',
                'inquiries_issuer_bp_id_status_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }
            if (Schema::hasColumn('inquiries', 'assignee_bp_id')) {
                $table->dropConstrainedForeignId('assignee_bp_id');
            }
            if (Schema::hasColumn('inquiries', 'issuer_bp_id')) {
                $table->dropConstrainedForeignId('issuer_bp_id');
            }
            $drop = array_values(array_filter([
                Schema::hasColumn('inquiries', 'code') ? 'code' : null,
                Schema::hasColumn('inquiries', 'assignee_type') ? 'assignee_type' : null,
                Schema::hasColumn('inquiries', 'visibility') ? 'visibility' : null,
            ]));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
            if (! Schema::hasColumn('inquiries', 'owning_bp_id')) {
                $table->foreignId('owning_bp_id')->nullable()->constrained('business_partners')->cascadeOnDelete();
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $table, string $name, array $columns): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]))->isNotEmpty();
        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns) {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUniqueIndex(string $table, string $name, array $columns): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]))->isNotEmpty();
        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns) {
            $blueprint->unique($columns, $name);
        });
    }
};
