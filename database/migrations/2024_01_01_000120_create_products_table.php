<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->boolean('published')->default(false);
            $table->string('receipt_number', 100)->nullable();
            $table->string('image')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('company_id');
            $table->unique(['company_id', 'sku'], 'idx_product_company_sku')->whereNotNull('sku');
            $table->unique(['company_id', 'barcode'], 'idx_product_company_barcode')->whereNotNull('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
