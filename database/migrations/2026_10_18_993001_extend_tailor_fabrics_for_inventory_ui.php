<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow metre ("m") besides goj / gaj.
        DB::statement("ALTER TABLE tailor_fabrics MODIFY unit VARCHAR(10) NOT NULL DEFAULT 'm'");

        Schema::table('tailor_fabrics', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
            $table->string('hex_color', 20)->nullable()->after('color');
            $table->decimal('reorder_level', 10, 2)->default(0)->after('stock_quantity');
        });

        Schema::create('tailor_fabric_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('fabric_id');
            // opening | restock | order_use | adjustment
            $table->string('type', 30);
            $table->decimal('quantity', 12, 2);            // signed change
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'fabric_id']);
            $table->index(['company_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_fabric_movements');
        Schema::table('tailor_fabrics', function (Blueprint $table) {
            $table->dropColumn(['code', 'hex_color', 'reorder_level']);
        });
    }
};
