<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_variants')) {
            return;
        }
        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'wholesale_price')) {
                $table->decimal('wholesale_price', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('product_variants', 'min_wholesale_qty')) {
                $table->unsignedInteger('min_wholesale_qty')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_variants')) {
            return;
        }
        Schema::table('product_variants', function (Blueprint $table) {
            foreach (['wholesale_price', 'min_wholesale_qty'] as $c) {
                if (Schema::hasColumn('product_variants', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
