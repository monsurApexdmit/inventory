<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // Currency format settings
            $table->string('currency_symbol_position')->default('before')->after('currency');
            $table->string('currency_decimal_separator')->default('.')->after('currency_symbol_position');
            $table->string('currency_thousands_separator')->default(',')->after('currency_decimal_separator');
            $table->integer('currency_decimal_places')->default(2)->after('currency_thousands_separator');

            // Units of measurement
            $table->string('weight_unit')->default('kg')->after('currency_decimal_places');
            $table->string('dimension_unit')->default('cm')->after('weight_unit');

            // Date & Time format
            $table->string('date_format')->default('MM/DD/YYYY')->after('dimension_unit');
            $table->string('time_format')->default('12h')->after('date_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'currency_symbol_position',
                'currency_decimal_separator',
                'currency_thousands_separator',
                'currency_decimal_places',
                'weight_unit',
                'dimension_unit',
                'date_format',
                'time_format',
            ]);
        });
    }
};
