<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('guest_access_token', 64)->nullable()->after('customer_email');
            $table->index(['company_id', 'guest_access_token']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'guest_access_token']);
            $table->dropColumn('guest_access_token');
        });
    }
};
