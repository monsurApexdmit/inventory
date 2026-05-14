<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('product_type');
            $table->unsignedBigInteger('fabric_id')->nullable();
            $table->decimal('fabric_quantity', 10, 2)->default(0);
            $table->decimal('fabric_unit_price', 10, 2)->default(0);
            $table->unsignedBigInteger('measurement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('fabric_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_order_items');
    }
};
