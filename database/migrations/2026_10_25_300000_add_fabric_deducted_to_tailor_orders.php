<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tailor_orders', 'fabric_deducted')) {
            Schema::table('tailor_orders', function (Blueprint $t) {
                $t->boolean('fabric_deducted')->default(false);
            });
            // Existing orders had their fabric deducted at creation.
            DB::table('tailor_orders')->where('order_status', '!=', 'cancelled')->update(['fabric_deducted' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tailor_orders', 'fabric_deducted')) {
            Schema::table('tailor_orders', fn (Blueprint $t) => $t->dropColumn('fabric_deducted'));
        }
    }
};
