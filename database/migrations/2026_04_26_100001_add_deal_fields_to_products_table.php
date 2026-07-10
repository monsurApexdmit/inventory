<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('published');
            $table->boolean('is_hot_deal')->default(false)->after('is_featured');
            $table->boolean('is_best_seller')->default(false)->after('is_hot_deal');
            $table->string('deal_label', 50)->nullable()->after('is_best_seller');
            $table->index(['company_id', 'is_hot_deal']);
            $table->index(['company_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'is_hot_deal']);
            $table->dropIndex(['company_id', 'is_featured']);
            $table->dropColumn(['is_featured', 'is_hot_deal', 'is_best_seller', 'deal_label']);
        });
    }
};
