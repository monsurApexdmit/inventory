<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 255);
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('variant_name', 255)->nullable();
            $table->integer('quantity')->default(1);
            $table->float('price', 15, 2)->default(0);
            $table->string('reason', 255);
            $table->timestamps();

            $table->foreign('return_id')->references('id')->on('customer_returns')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
            $table->index('return_id');
            $table->index('product_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_return_items');
    }
};
