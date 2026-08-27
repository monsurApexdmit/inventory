<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The salary-payment service writes `payment_method`, but the column was never
// created — inserts blew up with "Unknown column 'payment_method'".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
