<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('type', 20)->default('Count')->after('symbol');
            $table->decimal('conversion_qty', 15, 4)->nullable()->after('type');
            $table->unsignedBigInteger('conversion_unit_id')->nullable()->after('conversion_qty');
            $table->foreign('conversion_unit_id')->references('id')->on('units')->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('image')->nullable();
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->string('website')->nullable();
            $table->text('description')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->date('offer_ends_at')->nullable();
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->unsignedInteger('min_wholesale_qty')->nullable();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('channel_pos')->default(true);
            $table->boolean('channel_online')->default(true);
            $table->boolean('once_per_customer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $t) => $t->dropColumn(['channel_pos', 'channel_online', 'once_per_customer']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['offer_ends_at', 'wholesale_price', 'min_wholesale_qty']));
        Schema::table('brands', fn (Blueprint $t) => $t->dropColumn(['website', 'description']));
        Schema::table('categories', fn (Blueprint $t) => $t->dropColumn(['description', 'image']));
        Schema::table('units', function (Blueprint $t) {
            $t->dropForeign(['conversion_unit_id']);
            $t->dropColumn(['type', 'conversion_qty', 'conversion_unit_id']);
        });
    }
};
