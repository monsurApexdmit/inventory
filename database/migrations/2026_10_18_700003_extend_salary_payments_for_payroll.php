<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->decimal('base_amount', 15, 2)->nullable();
            $table->decimal('bonus', 15, 2)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->string('bonus_note')->nullable();
            $table->string('deduction_note')->nullable();
        });

        // amount stays the net pay; existing rows had no adjustments so base == amount.
        DB::statement('UPDATE salary_payments SET base_amount = amount');
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn(['base_amount', 'bonus', 'deduction', 'bonus_note', 'deduction_note']);
        });
    }
};
