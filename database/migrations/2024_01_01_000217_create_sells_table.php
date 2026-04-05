<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sells', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_no', 100)->unique();
            $table->timestamp('order_time');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 255);
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->string('shipping_full_name', 255)->nullable();
            $table->string('shipping_phone', 50)->nullable();
            $table->string('shipping_email', 255)->nullable();
            $table->string('shipping_address_line1', 255)->nullable();
            $table->string('shipping_address_line2', 255)->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();
            $table->string('shipping_address_type', 50)->default('other');
            $table->string('method', 100)->default('Cash'); // Cash, Card, Online
            $table->float('amount', 15, 2)->default(0);
            $table->float('shipping_cost', 15, 2)->default(0);
            $table->string('shipping_method', 100)->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->string('coupon_code', 100)->nullable();
            $table->float('discount', 15, 2)->default(0);
            $table->string('status', 50)->default('Pending'); // Pending, Processing, Delivered
            $table->boolean('stock_deducted')->default(false);
            $table->string('payment_status', 50)->default('pending'); // pending, paid, partially_paid, refunded, failed
            $table->string('fulfillment_status', 50)->default('unfulfilled'); // unfulfilled, processing, shipped, delivered, cancelled
            $table->string('tracking_number', 100)->nullable();
            $table->string('carrier', 100)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->float('total_cost', 15, 2)->default(0);
            $table->float('gross_profit', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('shipping_address_id')->references('id')->on('shipping_addresses')->nullOnDelete();
            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();

            // Indexes
            $table->index('company_id');
            $table->index('customer_id');
            $table->index('order_time');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sells');
    }
};
