<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('order_shipments', 'cod_collected')) {
                $table->boolean('cod_collected')->default(false)->after('cod_amount');
            }
            if (!Schema::hasColumn('order_shipments', 'cod_collected_amount')) {
                $table->decimal('cod_collected_amount', 12, 2)->nullable()->after('cod_collected');
            }
            if (!Schema::hasColumn('order_shipments', 'cod_collected_at')) {
                $table->timestamp('cod_collected_at')->nullable()->after('cod_collected_amount');
            }
            if (!Schema::hasColumn('order_shipments', 'rider')) {
                $table->string('rider', 100)->nullable()->after('carrier');
            }
            if (!Schema::hasColumn('order_shipments', 'package')) {
                $table->string('package', 50)->nullable()->after('dimensions');
            }
        });

        // Unique tracking number per company (skip if legacy duplicates exist)
        $dupes = Schema::getConnection()->select(
            'select 1 from order_shipments group by company_id, tracking_number having count(*) > 1 limit 1'
        );
        if (empty($dupes)) {
            Schema::table('order_shipments', function (Blueprint $table) {
                $table->unique(['company_id', 'tracking_number'], 'order_shipments_company_tracking_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            try { $table->dropUnique('order_shipments_company_tracking_unique'); } catch (\Throwable $e) {}
            foreach (['cod_collected', 'cod_collected_amount', 'cod_collected_at', 'rider', 'package'] as $c) {
                if (Schema::hasColumn('order_shipments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
