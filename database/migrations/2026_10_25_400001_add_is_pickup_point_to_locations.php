<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('locations')) {
            return;
        }
        if (!Schema::hasColumn('locations', 'is_pickup_point')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->boolean('is_pickup_point')->default(false);
            });
            // Backfill so existing pickup behaviour (previously ships_online) keeps working.
            if (Schema::hasColumn('locations', 'ships_online')) {
                DB::table('locations')->where('ships_online', true)->update(['is_pickup_point' => true]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'is_pickup_point')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('is_pickup_point');
            });
        }
    }
};
