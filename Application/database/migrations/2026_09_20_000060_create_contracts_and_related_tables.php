<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('owning_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owning_bp_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('partition_price', 12, 2);
            $table->boolean('price_locked')->default(false);
            $table->timestamps();

            $table->unique(['contract_id', 'item_id']);
        });

        Schema::create('contract_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['contract_id', 'created_at']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('from_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignId('to_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->json('payload_json')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['to_bp_id', 'status']);
            $table->index(['contract_id', 'type']);
        });

        Schema::create('data_field_names', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('contract_item_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_item_id')->constrained('contract_items')->cascadeOnDelete();
            $table->foreignId('data_field_name_id')->nullable()->constrained('data_field_names')->nullOnDelete();
            $table->string('name');
            $table->string('value', 1000);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('contract_item_id');
        });

        Schema::create('item_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('item_id');
        });

        Schema::create('contract_item_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_item_id')->constrained('contract_items')->cascadeOnDelete();
            $table->foreignId('item_document_id')->nullable()->constrained('item_documents')->nullOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->timestamps();

            $table->index('contract_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_item_documents');
        Schema::dropIfExists('item_documents');
        Schema::dropIfExists('contract_item_data');
        Schema::dropIfExists('data_field_names');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('contract_status_histories');
        Schema::dropIfExists('contract_items');
        Schema::dropIfExists('contracts');
    }
};
