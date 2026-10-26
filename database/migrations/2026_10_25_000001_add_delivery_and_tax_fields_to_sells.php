<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            if (!Schema::hasColumn('sells', 'transaction_id')) {
                $table->string('transaction_id', 100)->nullable();
            }
            if (!Schema::hasColumn('sells', 'zone_name')) {
                $table->string('zone_name', 150)->nullable();
            }
            if (!Schema::hasColumn('sells', 'delivery_note')) {
                $table->text('delivery_note')->nullable();
            }
            if (!Schema::hasColumn('sells', 'tax_rate')) {
                $table->decimal('tax_rate', 6, 2)->nullable();
            }
            if (!Schema::hasColumn('sells', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            foreach (['transaction_id', 'zone_name', 'delivery_note', 'tax_rate', 'tax_amount'] as $c) {
                if (Schema::hasColumn('sells', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
