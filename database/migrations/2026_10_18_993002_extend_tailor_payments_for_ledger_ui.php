<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dorji payouts have no order.
        DB::statement('ALTER TABLE tailor_payments MODIFY order_id BIGINT UNSIGNED NULL');

        Schema::table('tailor_payments', function (Blueprint $table) {
            $table->string('kind', 20)->default('customer')->after('company_id');   // customer | dorji
            $table->unsignedBigInteger('dorji_id')->nullable()->after('order_id');
            $table->string('payment_type', 20)->nullable()->after('payment_method'); // advance | payment | final | payout
            $table->unsignedBigInteger('created_by')->nullable();
            $table->index(['company_id', 'kind', 'payment_date']);
            $table->index('dorji_id');
        });

        // How much of an assignment's dorji_charge has been paid out.
        Schema::table('tailor_assignments', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)->default(0)->after('dorji_charge');
        });

        DB::table('tailor_payments')->where('notes', 'Advance payment')->update(['payment_type' => 'advance']);
        DB::table('tailor_payments')->whereNull('payment_type')->update(['payment_type' => 'payment']);
    }

    public function down(): void
    {
        Schema::table('tailor_assignments', fn (Blueprint $t) => $t->dropColumn('paid_amount'));
        Schema::table('tailor_payments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind', 'payment_date']);
            $table->dropIndex(['dorji_id']);
            $table->dropColumn(['kind', 'dorji_id', 'payment_type', 'created_by']);
        });
    }
};
