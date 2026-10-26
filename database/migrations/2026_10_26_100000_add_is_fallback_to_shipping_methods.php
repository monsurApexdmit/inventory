<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipping_methods', 'is_fallback')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                // Delivery zone used for "all other areas" when no listed area matches
                $table->boolean('is_fallback')->default(false)->after('areas_covered');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipping_methods', 'is_fallback')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->dropColumn('is_fallback');
            });
        }
    }
};
