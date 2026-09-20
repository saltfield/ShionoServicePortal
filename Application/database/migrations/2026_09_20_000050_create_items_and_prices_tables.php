<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('billing_type', ['initial', 'running']);
            $table->foreignId('required_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('partition_price', 12, 2)->default(0);
            $table->decimal('recommended_price', 12, 2)->default(0);
            $table->decimal('user_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('billing_type');
            $table->index('is_active');
        });

        Schema::create('bp_wholesale_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('seller_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignId('buyer_bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['item_id', 'seller_bp_id', 'buyer_bp_id'], 'bp_wholesale_prices_unique');
            $table->index(['seller_bp_id', 'buyer_bp_id']);
        });

        Schema::create('customer_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('bp_id')->constrained('business_partners')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['item_id', 'bp_id', 'customer_id'], 'customer_prices_unique');
            $table->index(['bp_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_prices');
        Schema::dropIfExists('bp_wholesale_prices');
        Schema::dropIfExists('items');
    }
};
