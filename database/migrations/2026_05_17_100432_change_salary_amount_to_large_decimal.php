<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->decimal('amount', 22, 2)->change();
            $table->decimal('paid_amount', 22, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->change();
            $table->decimal('paid_amount', 15, 2)->default(0)->change();
        });
    }
};
