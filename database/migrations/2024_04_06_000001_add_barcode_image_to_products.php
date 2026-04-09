<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Add barcode_image_path column if it doesn't exist
            if (!Schema::hasColumn('products', 'barcode_image_path')) {
                $table->string('barcode_image_path')->nullable()->after('barcode');
                $table->index('barcode'); // Add index for faster barcode lookups
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['barcode']);
            $table->dropColumn('barcode_image_path');
        });
    }
};
