<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concurrency-safe checkout:
 *  - idempotency_key: one order per checkout attempt (unique per company), so a double submit or a
 *    client retry returns the first order instead of taking stock twice.
 *  - stock_hold_expires_at: online-gateway orders hold their stock until this time; unpaid orders past
 *    it are cancelled and their stock released by `orders:release-expired`.
 *  - cancel_reason: why an order was cancelled (payment failed / expired / staff).
 *  - payment_url: gateway redirect, so a replayed checkout can send the buyer back to the same payment.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            if (!Schema::hasColumn('sells', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('invoice_no');
                $table->unique(['company_id', 'idempotency_key'], 'sells_company_idempotency_unique');
            }
            if (!Schema::hasColumn('sells', 'stock_hold_expires_at')) {
                $table->timestamp('stock_hold_expires_at')->nullable()->after('stock_deducted');
                $table->index(['payment_status', 'stock_hold_expires_at'], 'sells_hold_expiry_index');
            }
            if (!Schema::hasColumn('sells', 'cancel_reason')) {
                $table->string('cancel_reason', 255)->nullable()->after('status');
            }
            if (!Schema::hasColumn('sells', 'payment_url')) {
                $table->text('payment_url')->nullable()->after('payment_transaction_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            if (Schema::hasColumn('sells', 'idempotency_key')) {
                $table->dropUnique('sells_company_idempotency_unique');
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('sells', 'stock_hold_expires_at')) {
                $table->dropIndex('sells_hold_expiry_index');
                $table->dropColumn('stock_hold_expires_at');
            }
            foreach (['cancel_reason', 'payment_url'] as $col) {
                if (Schema::hasColumn('sells', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
