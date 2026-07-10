<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_orders', function (Blueprint $table) {
            $table->string('tracking_token', 32)->nullable()->unique()->after('order_number');
        });

        // Backfill existing orders
        \DB::table('tailor_orders')->whereNull('tracking_token')->orderBy('id')->each(function ($row) {
            \DB::table('tailor_orders')->where('id', $row->id)->update([
                'tracking_token' => strtoupper(Str::random(12)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tailor_orders', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
