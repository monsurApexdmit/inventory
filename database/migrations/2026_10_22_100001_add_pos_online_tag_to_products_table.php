<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'available_in_pos')) {
                $table->boolean('available_in_pos')->default(true);
            }
            if (!Schema::hasColumn('products', 'visible_online')) {
                $table->boolean('visible_online')->default(true);
            }
            if (!Schema::hasColumn('products', 'tag')) {
                $table->string('tag', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['available_in_pos', 'visible_online', 'tag'] as $c) {
                if (Schema::hasColumn('products', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
