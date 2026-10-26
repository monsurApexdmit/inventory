<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('discount_type', 10)->default('flat');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
        });

        // Backfill timestamps for existing orders.
        DB::table('purchase_orders')->whereIn('status', ['sent', 'partial', 'received'])->update(['sent_at' => DB::raw('created_at')]);
        DB::table('purchase_orders')->where('status', 'received')->update(['received_at' => DB::raw('updated_at')]);

        Schema::create('purchase_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamp('received_at');
            $table->json('items');
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipts');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'tax_rate', 'shipping_cost', 'sent_at', 'received_at']);
        });
    }
};
