<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->decimal('shipping_deposit_amount', 10, 2)->default(0)->after('shipping_cost');
            $table->string('shipping_deposit_transaction_id')->nullable()->after('payment_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->dropColumn(['shipping_deposit_amount', 'shipping_deposit_transaction_id']);
        });
    }
};
