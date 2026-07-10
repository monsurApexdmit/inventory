<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->unique();
            $table->unsignedBigInteger('customer_id');
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->decimal('stitching_charge', 10, 2)->default(0);
            $table->decimal('extra_charge', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('due_amount', 10, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->enum('order_status', [
                'pending',
                'measurement_taken',
                'assigned',
                'cutting',
                'stitching',
                'ready',
                'delivered',
                'cancelled',
            ])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index(['company_id', 'order_status']);
            $table->index(['company_id', 'payment_status']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_orders');
    }
};
