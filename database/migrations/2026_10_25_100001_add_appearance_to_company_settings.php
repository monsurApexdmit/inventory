<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('company_settings', 'accent_name')) {
                $table->string('accent_name', 40)->nullable();
            }
            foreach (['brand_primary_color', 'brand_accent_color', 'brand_background_color'] as $c) {
                if (!Schema::hasColumn('company_settings', $c)) {
                    $table->string($c, 9)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        foreach (['accent_name', 'brand_primary_color', 'brand_accent_color', 'brand_background_color'] as $c) {
            if (Schema::hasColumn('company_settings', $c)) {
                Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
