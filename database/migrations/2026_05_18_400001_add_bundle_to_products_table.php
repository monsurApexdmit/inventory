<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_bundle')->default(false)->after('reorder_point');
            $table->decimal('bundle_price_override', 15, 2)->nullable()->after('is_bundle');
        });

        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('bundle_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_bundle', 'bundle_price_override']);
        });
    }
};
