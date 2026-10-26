<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The JWT actor is usually a saas/staff user (not in `users`), so keep a display-name snapshot.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('created_by_name', 150)->nullable()->after('created_by');
        });
        Schema::table('transfer_documents', function (Blueprint $table) {
            $table->string('created_by_name', 150)->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropColumn('created_by_name'));
        Schema::table('transfer_documents', fn (Blueprint $t) => $t->dropColumn('created_by_name'));
    }
};
