<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'billing_period')) {
                $table->string('billing_period', 20)->nullable()->after('status'); // monthly | yearly (chosen at checkout)
            }
            if (!Schema::hasColumn('subscriptions', 'cancel_reason')) {
                $table->string('cancel_reason')->nullable()->after('cancelled_at');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'plan_name')) {
                $table->string('plan_name')->nullable()->after('description');
            }
            if (!Schema::hasColumn('payments', 'billing_period')) {
                $table->string('billing_period', 20)->nullable()->after('plan_name');
            }
            if (!Schema::hasColumn('payments', 'subtotal')) {
                $table->bigInteger('subtotal')->nullable()->after('billing_period'); // cents, before tax
            }
            if (!Schema::hasColumn('payments', 'tax_amount')) {
                $table->bigInteger('tax_amount')->nullable()->after('subtotal'); // cents
            }
            if (!Schema::hasColumn('payments', 'billing_email')) {
                $table->string('billing_email')->nullable()->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['billing_period', 'cancel_reason'] as $c) {
                if (Schema::hasColumn('subscriptions', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('payments', function (Blueprint $table) {
            foreach (['plan_name', 'billing_period', 'subtotal', 'tax_amount', 'billing_email'] as $c) {
                if (Schema::hasColumn('payments', $c)) $table->dropColumn($c);
            }
        });
    }
};
