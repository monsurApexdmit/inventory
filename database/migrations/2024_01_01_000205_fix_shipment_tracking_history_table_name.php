<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename the table from shipment_tracking_history to shipment_tracking_histories
        if (Schema::hasTable('shipment_tracking_history')) {
            DB::statement('RENAME TABLE shipment_tracking_history TO shipment_tracking_histories');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipment_tracking_histories')) {
            DB::statement('RENAME TABLE shipment_tracking_histories TO shipment_tracking_history');
        }
    }
};
