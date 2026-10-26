<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tailor_orders', 'dorji_id')) {
            Schema::table('tailor_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('dorji_id')->nullable()->after('customer_id');
                $table->index('dorji_id');
            });
        }
    }

    public function down(): void
    {
        // Intentionally left in place: other features may rely on this column.
    }
};
