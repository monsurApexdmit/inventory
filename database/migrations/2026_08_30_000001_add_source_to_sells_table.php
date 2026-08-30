<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where a sell originated: 'pos', 'storefront', or 'manual' (Sales page).
// Lets the Sales page list only manually-entered sales.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->string('source', 20)->default('pos')->after('method');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
