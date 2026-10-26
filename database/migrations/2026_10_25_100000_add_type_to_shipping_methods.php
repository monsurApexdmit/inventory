<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipping_methods', 'type')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                // delivery = ships to an address; pickup = collected in store (free, no address needed)
                $table->string('type', 20)->default('delivery')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipping_methods', 'type')) {
            Schema::table('shipping_methods', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
