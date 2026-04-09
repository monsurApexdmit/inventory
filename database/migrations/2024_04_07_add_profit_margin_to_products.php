<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('profit_margin', 10, 2)->nullable()->after('cost_price')->comment('Profit margin value (percentage or flat amount)');
            $table->enum('margin_type', ['percentage', 'flat'])->default('percentage')->after('profit_margin')->comment('Type of profit margin: percentage or flat amount');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('profit_margin', 10, 2)->nullable()->after('cost_price')->comment('Profit margin value (percentage or flat amount)');
            $table->enum('margin_type', ['percentage', 'flat'])->default('percentage')->after('profit_margin')->comment('Type of profit margin: percentage or flat amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['profit_margin', 'margin_type']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['profit_margin', 'margin_type']);
        });
    }
};
