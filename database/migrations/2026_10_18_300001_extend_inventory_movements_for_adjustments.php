<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('adjustment_type', 20)->nullable()->after('type');
            $table->string('reason', 1000)->nullable()->after('notes');
            $table->integer('quantity_before')->nullable()->after('quantity');
            $table->integer('quantity_after')->nullable()->after('quantity_before');
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity_after');
            $table->boolean('flagged')->default(false)->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['adjustment_type', 'reason', 'quantity_before', 'quantity_after', 'unit_cost', 'flagged']);
        });
    }
};
