<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add to products table
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'barcode_code')) {
                $table->string('barcode_code')->nullable()->unique()->after('sku');
                $table->enum('barcode_format', ['CODE128', 'EAN13', 'UPC', 'QR'])->default('CODE128')->after('barcode_code');
                $table->index('barcode_code');
            }
        });

        // Add to product_variants table
        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'barcode_code')) {
                $table->string('barcode_code')->nullable()->unique()->after('sku');
                $table->enum('barcode_format', ['CODE128', 'EAN13', 'UPC', 'QR'])->default('CODE128')->after('barcode_code');
                $table->index('barcode_code');
                $table->index(['product_id', 'barcode_code']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'barcode_code')) {
                $table->dropIndex(['barcode_code']);
                $table->dropColumn(['barcode_code', 'barcode_format']);
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('product_variants', 'barcode_code')) {
                $table->dropIndex(['barcode_code']);
                $table->dropIndex(['product_id', 'barcode_code']);
                $table->dropColumn(['barcode_code', 'barcode_format']);
            }
        });
    }
};
