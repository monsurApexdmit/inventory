<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_fabrics', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_id')->nullable()->after('stock_quantity');
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
            $table->dropColumn('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('tailor_fabrics', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn('vendor_id');
            $table->string('supplier_name')->nullable();
        });
    }
};
