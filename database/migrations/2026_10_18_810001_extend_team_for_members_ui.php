<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_users', function (Blueprint $table) {
            if (!Schema::hasColumn('saas_users', 'phone')) {
                $table->string('phone', 50)->nullable()->after('full_name');
            }
        });

        Schema::table('invitations', function (Blueprint $table) {
            if (!Schema::hasColumn('invitations', 'phone')) {
                $table->string('phone', 50)->nullable()->after('full_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('saas_users', function (Blueprint $table) {
            if (Schema::hasColumn('saas_users', 'phone')) {
                $table->dropColumn('phone');
            }
        });

        Schema::table('invitations', function (Blueprint $table) {
            if (Schema::hasColumn('invitations', 'phone')) {
                $table->dropColumn('phone');
            }
        });
    }
};
