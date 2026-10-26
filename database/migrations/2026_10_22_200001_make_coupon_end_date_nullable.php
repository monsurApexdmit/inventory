<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('coupons') || !Schema::hasColumn('coupons', 'end_date')) {
            return;
        }
        // Optional end date ("No end"). Raw SQL: MySQL TIMESTAMP columns need an explicit NULL.
        DB::statement('ALTER TABLE coupons MODIFY end_date TIMESTAMP NULL DEFAULT NULL');
    }

    public function down(): void
    {
        // Left nullable on purpose: reverting would fail for rows without an end date.
    }
};
