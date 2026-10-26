<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // enum -> string so the new statuses / credit types are allowed
        Schema::table('vendor_returns', function (Blueprint $table) {
            $table->string('status', 30)->default('pending')->change();
            $table->string('credit_type', 30)->default('note')->change();
        });

        Schema::table('vendor_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('vendor_name');
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('location_id');
            $table->string('tracking_no', 100)->nullable()->after('credit_type');
            $table->timestamp('shipped_at')->nullable()->after('tracking_no');
            $table->timestamp('received_at')->nullable()->after('shipped_at');
            $table->timestamp('credited_at')->nullable()->after('received_at');
            $table->string('reason', 1000)->nullable()->after('credited_at');
            $table->decimal('transport_fee', 15, 2)->default(0)->after('total_amount');
            $table->boolean('credit_applied')->default(false)->after('credited_at');
            $table->index('location_id');
            $table->index('purchase_order_id');
        });

        DB::table('vendor_returns')->where('status', 'received_by_vendor')->update(['status' => 'received']);
        DB::table('vendor_returns')->where('status', 'completed')->update(['status' => 'credited', 'credited_at' => DB::raw('COALESCE(completed_date, updated_at)')]);
        DB::table('vendor_returns')->where('credit_type', 'credit_note')->update(['credit_type' => 'note']);
    }

    public function down(): void
    {
        Schema::table('vendor_returns', function (Blueprint $table) {
            $table->dropIndex(['location_id']);
            $table->dropIndex(['purchase_order_id']);
            $table->dropColumn(['location_id', 'purchase_order_id', 'tracking_no', 'shipped_at', 'received_at',
                'credited_at', 'reason', 'transport_fee', 'credit_applied']);
        });
    }
};
