<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add tracking_type to products and variants
        Schema::table('products', function (Blueprint $table) {
            $table->enum('tracking_type', ['none', 'serial', 'batch'])->default('none')->after('is_bundle');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->enum('tracking_type', ['none', 'serial', 'batch'])->default('none')->after('stock');
        });

        // Serial numbers — one per unit, unique per company
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 100);
            $table->enum('status', ['available', 'sold', 'returned', 'damaged'])->default('available');
            $table->string('purchase_order_number', 100)->nullable();
            $table->date('received_date')->nullable();
            $table->foreignId('sold_in_sell_id')->nullable()->constrained('sells')->nullOnDelete();
            $table->date('sold_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'serial_number']);
            $table->index(['product_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        // Batch numbers — one batch can cover many units
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_number', 100);
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_remaining')->default(0);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('purchase_order_number', 100)->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'batch_number']);
            $table->index(['product_id', 'expiry_date']);
            $table->index(['company_id', 'expiry_date']);
        });

        // Inventory movements — audit trail for serial/batch
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['receive', 'sell', 'return', 'transfer', 'adjustment', 'damage']);
            $table->string('reference_type', 50)->nullable(); // 'sell', 'purchase_order', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->integer('quantity');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'type']);
            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_serials');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('tracking_type');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracking_type');
        });
    }
};
