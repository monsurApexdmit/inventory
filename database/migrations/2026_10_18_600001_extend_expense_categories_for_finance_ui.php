<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('description');
            $table->decimal('monthly_budget', 15, 2)->nullable()->after('color');
            $table->string('type')->default('Operating cost')->after('monthly_budget');
            $table->boolean('status')->default(true)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn(['color', 'monthly_budget', 'type', 'status']);
        });
    }
};
