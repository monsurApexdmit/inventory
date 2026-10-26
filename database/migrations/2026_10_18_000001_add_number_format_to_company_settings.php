<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('company_settings', 'number_format')) {
            Schema::table('company_settings', function (Blueprint $table) {
                // 'intl' = 100,000.00  |  'lakh' = 1,00,000.00
                $table->string('number_format', 10)->default('intl');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'number_format')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->dropColumn('number_format');
            });
        }
    }
};
