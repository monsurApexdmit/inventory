<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });

        // Drop the unique index that prevents null company_id with duplicate emails
        // (super_admin email must be unique across the whole table anyway)
        // MySQL unique index allows multiple NULLs so this is safe as-is.
    }

    public function down(): void
    {
        Schema::table('saas_users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }
};
