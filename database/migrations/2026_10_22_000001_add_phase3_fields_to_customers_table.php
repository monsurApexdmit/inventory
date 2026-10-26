<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'type')) {
                $table->string('type', 20)->default('individual');
            }
            if (!Schema::hasColumn('customers', 'business_name')) {
                $table->string('business_name')->nullable();
            }
            if (!Schema::hasColumn('customers', 'licence_bin')) {
                $table->string('licence_bin', 100)->nullable();
            }
            if (!Schema::hasColumn('customers', 'customer_group')) {
                $table->string('customer_group', 20)->default('retail');
            }
            if (!Schema::hasColumn('customers', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('customers', 'opening_balance')) {
                $table->decimal('opening_balance', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('customers', 'birthday')) {
                $table->date('birthday')->nullable();
            }
            if (!Schema::hasColumn('customers', 'tags')) {
                $table->json('tags')->nullable();
            }
            if (!Schema::hasColumn('customers', 'sms_opt_in')) {
                $table->boolean('sms_opt_in')->default(true);
            }
            if (!Schema::hasColumn('customers', 'district')) {
                $table->string('district')->nullable();
            }
            if (!Schema::hasColumn('customers', 'area')) {
                $table->string('area')->nullable();
            }
        });

        // Existing retail/wholesale values live in customer_type; carry them into the new group column.
        if (Schema::hasColumn('customers', 'customer_type') && Schema::hasColumn('customers', 'customer_group')) {
            DB::table('customers')->where('customer_type', 'wholesale')->update(['customer_group' => 'wholesale']);
        }
    }

    public function down(): void
    {
        foreach (['type', 'business_name', 'licence_bin', 'customer_group', 'credit_limit', 'opening_balance', 'birthday', 'tags', 'sms_opt_in', 'district', 'area'] as $col) {
            if (Schema::hasColumn('customers', $col)) {
                Schema::table('customers', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
