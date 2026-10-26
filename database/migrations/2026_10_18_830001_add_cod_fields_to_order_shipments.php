<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->decimal('cod_amount', 12, 2)->default(0)->after('shipping_cost');
            $table->string('delivery_payer', 20)->default('customer')->after('cod_amount');
            $table->string('delivery_speed', 20)->nullable()->after('delivery_payer');
            $table->date('pickup_date')->nullable()->after('delivery_speed');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn(['cod_amount', 'delivery_payer', 'delivery_speed', 'pickup_date']);
        });
    }
};
