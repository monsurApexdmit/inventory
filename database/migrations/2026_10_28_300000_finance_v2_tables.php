<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance v2: payment-method fee settings, month-end closes, finance activity log, expense voids,
 * supplier receipts on manual entries and movement ↔ order line links.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('finance_settings')) {
            Schema::create('finance_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->json('payment_fees')->nullable(); // {methodKey: {percent, fixed}}
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('finance_period_closes')) {
            Schema::create('finance_period_closes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->char('month', 7); // YYYY-MM
                $table->date('period_start');
                $table->date('period_end');
                $table->boolean('is_closed')->default(true);
                $table->decimal('net_at_close', 15, 2)->nullable();
                $table->json('snapshot')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->string('closed_by_name', 150)->nullable();
                $table->timestamp('reopened_at')->nullable();
                $table->unsignedBigInteger('reopened_by')->nullable();
                $table->string('reopened_by_name', 150)->nullable();
                $table->string('reopen_reason', 500)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'month']);
                $table->index(['company_id', 'is_closed']);
            });
        }

        if (!Schema::hasTable('finance_activity_log')) {
            Schema::create('finance_activity_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('subject_type', 40); // expense|period|entry|opening_stock|sale_movements
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('action', 30);       // created|edited|voided|deleted|closed|reopened|posted
                $table->json('changes')->nullable();
                $table->string('note', 1000)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name', 150)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['company_id', 'subject_type', 'subject_id'], 'fal_subject_idx');
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'voided_at')) {
                $table->date('voided_at')->nullable()->after('salary_payment_id');
                $table->string('void_reason', 500)->nullable()->after('voided_at');
                $table->unsignedBigInteger('voided_by')->nullable()->after('void_reason');
                $table->string('voided_by_name', 150)->nullable()->after('voided_by');
            }
        });

        Schema::table('finance_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('finance_entries', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('account_id');
                $table->index(['company_id', 'vendor_id']);
            }
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_movements', 'order_item_id')) {
                $table->unsignedBigInteger('order_item_id')->nullable()->after('reference_id');
                $table->index('order_item_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_movements', 'order_item_id')) {
                $table->dropIndex(['order_item_id']);
                $table->dropColumn('order_item_id');
            }
        });
        Schema::table('finance_entries', function (Blueprint $table) {
            if (Schema::hasColumn('finance_entries', 'vendor_id')) {
                $table->dropIndex(['company_id', 'vendor_id']);
                $table->dropColumn('vendor_id');
            }
        });
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'voided_at')) {
                $table->dropColumn(['voided_at', 'void_reason', 'voided_by', 'voided_by_name']);
            }
        });
        Schema::dropIfExists('finance_activity_log');
        Schema::dropIfExists('finance_period_closes');
        Schema::dropIfExists('finance_settings');
    }
};
