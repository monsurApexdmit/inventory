<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'sort_order')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0)->after('status');
            });
            // Keep the existing (creation) order for current rows
            DB::table('categories')->update(['sort_order' => DB::raw('id')]);
        }
        if (!Schema::hasColumn('categories', 'color')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('color', 20)->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        foreach (['sort_order', 'color'] as $col) {
            if (Schema::hasColumn('categories', $col)) {
                Schema::table('categories', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
