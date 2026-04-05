<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sell_id');
            $table->string('tracking_number', 100);
            $table->string('carrier', 100);
            $table->string('shipping_method', 100)->nullable();
            $table->string('status', 50)->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->float('shipping_cost', 15, 2)->default(0);
            $table->float('weight', 15, 2)->nullable();
            $table->string('dimensions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            // $table->foreign('sell_id')->references('id')->on('sells')->onDelete('cascade'); // TODO: uncomment when sells table exists
            $table->index('company_id');
            $table->index('sell_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
