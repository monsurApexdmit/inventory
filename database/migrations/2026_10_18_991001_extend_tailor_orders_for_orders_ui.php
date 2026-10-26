<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('tailor_orders', 'is_urgent')) {
                $table->boolean('is_urgent')->default(false)->after('discount');
            }
            if (!Schema::hasColumn('tailor_orders', 'urgent_fee')) {
                $table->decimal('urgent_fee', 10, 2)->default(0)->after('is_urgent');
            }
        });

        Schema::table('tailor_order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('tailor_order_items', 'quantity')) {
                $table->unsignedInteger('quantity')->default(1)->after('product_type');
            }
            if (!Schema::hasColumn('tailor_order_items', 'stitching_price')) {
                $table->decimal('stitching_price', 10, 2)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('tailor_order_items', 'fabric_source')) {
                $table->string('fabric_source', 10)->default('shop')->after('stitching_price');
            }
            if (!Schema::hasColumn('tailor_order_items', 'own_fabric_note')) {
                $table->text('own_fabric_note')->nullable()->after('fabric_source');
            }
            if (!Schema::hasColumn('tailor_order_items', 'style_options')) {
                $table->json('style_options')->nullable()->after('own_fabric_note');
            }
        });

        // Add the "trial" stage to the order pipeline (cutting -> stitching -> trial -> ready).
        DB::statement("ALTER TABLE tailor_orders MODIFY COLUMN order_status ENUM('pending','measurement_taken','assigned','cutting','stitching','trial','ready','delivered','cancelled') NOT NULL DEFAULT 'pending'");

        if (!$this->hasIndex('tailor_orders', 'tailor_orders_company_delivery_idx')) {
            Schema::table('tailor_orders', function (Blueprint $table) {
                $table->index(['company_id', 'delivery_date'], 'tailor_orders_company_delivery_idx');
            });
        }
    }

    public function down(): void
    {
        DB::table('tailor_orders')->where('order_status', 'trial')->update(['order_status' => 'stitching']);
        DB::statement("ALTER TABLE tailor_orders MODIFY COLUMN order_status ENUM('pending','measurement_taken','assigned','cutting','stitching','ready','delivered','cancelled') NOT NULL DEFAULT 'pending'");

        Schema::table('tailor_orders', function (Blueprint $table) {
            $table->dropIndex('tailor_orders_company_delivery_idx');
            $table->dropColumn(['is_urgent', 'urgent_fee']);
        });
        Schema::table('tailor_order_items', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'stitching_price', 'fabric_source', 'own_fabric_note', 'style_options']);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($i) => $i->Key_name === $name);
    }
};
