<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('order_number');
            $table->boolean('restock')->default(true)->after('location_id');
            $table->decimal('subtotal', 15, 2)->default(0)->after('total_amount');
            $table->decimal('refund_amount', 15, 2)->default(0)->after('subtotal');
            $table->decimal('write_off_value', 15, 2)->default(0)->after('refund_amount');
            $table->string('reason', 1000)->nullable()->after('refund_method');
            $table->text('rejection_reason')->nullable()->after('notes');
            $table->boolean('is_request')->default(false)->after('status');
            $table->boolean('credited_to_customer')->default(false)->after('is_request');
            $table->index('location_id');
        });

        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sell_item_id')->nullable()->after('return_id');
            $table->decimal('unit_cost', 15, 2)->nullable()->after('price');
            $table->index('sell_item_id');
        });

        // Backfill legacy rows.
        DB::table('customer_returns')->update(['subtotal' => DB::raw('total_amount')]);
        DB::table('customer_returns')->where('status', 'pending')->update(['is_request' => true]);
        DB::table('customer_returns')
            ->whereIn('status', ['approved', 'completed'])
            ->whereIn('refund_method', ['cash', 'original_payment', 'original'])
            ->update(['refund_amount' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('customer_return_items', function (Blueprint $table) {
            $table->dropIndex(['sell_item_id']);
            $table->dropColumn(['sell_item_id', 'unit_cost']);
        });
        Schema::table('customer_returns', function (Blueprint $table) {
            $table->dropIndex(['location_id']);
            $table->dropColumn(['location_id', 'restock', 'subtotal', 'refund_amount', 'write_off_value',
                'reason', 'rejection_reason', 'is_request', 'credited_to_customer']);
        });
    }
};
