<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'auto_invoice_enabled')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->boolean('auto_invoice_enabled')->default(true)->after('minimum_term_months_snapshot');
                $table->boolean('billing_suspended')->default(false)->after('auto_invoice_enabled');
                $table->boolean('end_user_billing_disabled')->default(false)->after('billing_suspended');
                $table->boolean('bill_initial_in_system')->default(true)->after('end_user_billing_disabled');
                $table->string('kickback_start_year_month', 6)->nullable()->after('bill_initial_in_system');
                $table->boolean('recalc_on_price_change')->default(true)->after('kickback_start_year_month');
            });
        }

        if (! Schema::hasColumn('contract_items', 'bill_initial_in_system')) {
            Schema::table('contract_items', function (Blueprint $table) {
                $table->boolean('bill_initial_in_system')->nullable()->after('price_locked');
                $table->boolean('end_user_billing_disabled')->nullable()->after('bill_initial_in_system');
            });
        }

        if (! Schema::hasTable('contract_item_price_layers')) {
            Schema::create('contract_item_price_layers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_item_id')->constrained('contract_items')->cascadeOnDelete();
                $table->foreignId('seller_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->foreignId('buyer_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->unsignedBigInteger('amount')->default(0);
                $table->unsignedSmallInteger('depth_from_root')->default(0);
                $table->timestamps();

                $table->unique(['contract_item_id', 'seller_bp_id', 'buyer_bp_id'], 'cip_layers_unique');
                $table->index(['contract_item_id', 'depth_from_root'], 'cip_layers_depth_idx');
            });
        }

        if (! Schema::hasColumn('invoices', 'issuer_bp_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('issuer_bp_id')->nullable()->after('owning_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->string('source', 16)->default('auto')->after('issuer_bp_id');
                $table->string('due_year_month', 6)->nullable()->after('billing_year_month');
                $table->timestamp('withdrawn_at')->nullable()->after('paid_at');
            });

            DB::table('invoices')->whereNull('issuer_bp_id')->update([
                'issuer_bp_id' => DB::raw('owning_bp_id'),
            ]);
            DB::table('invoices')->where('status', 'cancelled')->update([
                'status' => 'withdrawn',
                'withdrawn_at' => DB::raw('COALESCE(cancelled_at, NOW())'),
            ]);

            foreach (DB::table('invoices')->whereNull('due_year_month')->get() as $row) {
                $ym = (string) $row->billing_year_month;
                if (preg_match('/^\d{6}$/', $ym) !== 1) {
                    continue;
                }
                $due = \Illuminate\Support\Carbon::createFromFormat('Ym', $ym)->addMonthNoOverflow()->format('Ym');
                DB::table('invoices')->where('id', $row->id)->update(['due_year_month' => $due]);
            }
        }

        if (Schema::hasColumn('invoices', 'cancelled_at')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('cancelled_at');
            });
        }

        if (! Schema::hasTable('kickback_invoices')) {
            Schema::create('kickback_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->unique();
                $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
                $table->foreignId('from_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->foreignId('to_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->string('billing_year_month', 6);
                $table->string('due_year_month', 6)->nullable();
                $table->string('status', 32);
                $table->unsignedBigInteger('subtotal')->default(0);
                $table->unsignedBigInteger('tax_total')->default(0);
                $table->unsignedBigInteger('total')->default(0);
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->string('note', 1000)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['from_bp_id', 'billing_year_month']);
                $table->index(['to_bp_id', 'billing_year_month']);
                $table->index(['contract_id', 'billing_year_month']);
            });
        }

        if (! Schema::hasTable('kickback_invoice_lines')) {
            Schema::create('kickback_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('kickback_invoice_id')->constrained('kickback_invoices')->cascadeOnDelete();
                $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->foreignId('seller_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->foreignId('buyer_bp_id')->constrained('business_partners')->restrictOnDelete();
                $table->string('description');
                $table->unsignedBigInteger('upper_amount')->default(0);
                $table->unsignedBigInteger('partition_amount')->default(0);
                $table->unsignedBigInteger('amount')->default(0);
                $table->unsignedTinyInteger('tax_rate')->default(10);
                $table->unsignedBigInteger('tax_amount')->default(0);
                $table->unsignedBigInteger('amount_inclusive')->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('kickback_invoice_id');
            });
        }

        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('billing_batch_errors')) {
            Schema::create('billing_batch_errors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $table->string('billing_year_month', 6);
                $table->string('phase', 32);
                $table->string('message', 1000);
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['billing_year_month', 'created_at']);
                $table->index('contract_id');
            });
        }

        if (! DB::table('system_settings')->where('key', 'billing_batch_schedule')->exists()) {
            DB::table('system_settings')->insert([
                'key' => 'billing_batch_schedule',
                'value' => json_encode([
                    'enabled' => true,
                    'day_mode' => 'month_end',
                    'day_of_month' => null,
                    'time' => '10:00',
                    'timezone' => 'Asia/Tokyo',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batch_errors');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('kickback_invoice_lines');
        Schema::dropIfExists('kickback_invoices');

        if (Schema::hasColumn('invoices', 'issuer_bp_id')) {
            if (! Schema::hasColumn('invoices', 'cancelled_at')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->timestamp('cancelled_at')->nullable()->after('paid_at');
                });
            }
            DB::table('invoices')->where('status', 'withdrawn')->update([
                'status' => 'cancelled',
                'cancelled_at' => DB::raw('COALESCE(withdrawn_at, NOW())'),
            ]);
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('issuer_bp_id');
                $table->dropColumn(['source', 'due_year_month', 'withdrawn_at']);
            });
        }

        Schema::dropIfExists('contract_item_price_layers');

        if (Schema::hasColumn('contract_items', 'bill_initial_in_system')) {
            Schema::table('contract_items', function (Blueprint $table) {
                $table->dropColumn(['bill_initial_in_system', 'end_user_billing_disabled']);
            });
        }

        if (Schema::hasColumn('contracts', 'auto_invoice_enabled')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn([
                    'auto_invoice_enabled',
                    'billing_suspended',
                    'end_user_billing_disabled',
                    'bill_initial_in_system',
                    'kickback_start_year_month',
                    'recalc_on_price_change',
                ]);
            });
        }
    }
};
