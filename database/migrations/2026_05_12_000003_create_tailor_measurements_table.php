<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('product_type');
            $table->decimal('chest', 5, 1)->nullable();
            $table->decimal('waist', 5, 1)->nullable();
            $table->decimal('hip', 5, 1)->nullable();
            $table->decimal('shoulder', 5, 1)->nullable();
            $table->decimal('sleeve', 5, 1)->nullable();
            $table->decimal('length', 5, 1)->nullable();
            $table->decimal('neck', 5, 1)->nullable();
            $table->decimal('bottom_length', 5, 1)->nullable();
            $table->decimal('inseam', 5, 1)->nullable();
            $table->decimal('pajama_waist', 5, 1)->nullable();
            $table->decimal('pajama_length', 5, 1)->nullable();
            $table->json('custom_fields')->nullable();
            $table->text('notes')->nullable();
            $table->date('measured_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_measurements');
    }
};
