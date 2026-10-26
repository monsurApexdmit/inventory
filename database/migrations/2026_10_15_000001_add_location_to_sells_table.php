<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            // Warehouse / branch the sale was made from (null = not tied to a branch, e.g. online orders)
            $table->unsignedBigInteger('location_id')->nullable()->after('source');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->dropIndex(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
