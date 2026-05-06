<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('offer_price', 10, 2)->nullable()->after('sale_price');
            $table->string('offer_type', 20)->nullable()->after('offer_price'); // 'percentage' or 'flat'
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('offer_price', 10, 2)->nullable()->after('sale_price');
            $table->string('offer_type', 20)->nullable()->after('offer_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['offer_price', 'offer_type']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['offer_price', 'offer_type']);
        });
    }
};
