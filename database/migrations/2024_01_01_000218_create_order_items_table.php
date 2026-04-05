<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sell_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('inventory_id')->nullable();
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->integer('quantity');
            $table->float('unit_price', 15, 2);
            $table->float('total_price', 15, 2);
            $table->float('unit_cost', 15, 2)->default(0);
            $table->float('total_cost', 15, 2)->default(0);
            $table->timestamps();

            // Foreign keys
            $table->foreign('sell_id')->references('id')->on('sells')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->foreign('inventory_id')->references('id')->on('variant_inventory')->nullOnDelete();

            // Indexes
            $table->index('sell_id');
            $table->index('product_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
