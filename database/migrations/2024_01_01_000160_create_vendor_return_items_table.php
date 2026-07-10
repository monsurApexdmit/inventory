<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('vendor_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name');
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('variant_name')->nullable();
            $table->integer('quantity')->default(1);
            $table->float('unit_price')->default(0);
            $table->float('total_price')->default(0);
            $table->float('unit_cost')->default(0);
            $table->string('reason');
            $table->timestamps();

            $table->index('return_id');
            $table->index('product_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_return_items');
    }
};
