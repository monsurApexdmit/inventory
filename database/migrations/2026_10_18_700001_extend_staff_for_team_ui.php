<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('nid')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('login_method', 20)->default('email');
            $table->string('pin_hash')->nullable();
            $table->json('location_ids')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('invited_at')->nullable();
        });

        // PIN-only staff have no email.
        Schema::table('staff', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Status is a free string: Active | Invited | On leave | Inactive. Normalise legacy casing.
        DB::table('staff')->whereRaw('LOWER(status) = ?', ['active'])->update(['status' => 'Active']);
        DB::table('staff')->whereRaw('LOWER(status) = ?', ['inactive'])->update(['status' => 'Inactive']);
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['nid', 'emergency_contact', 'login_method', 'pin_hash', 'location_ids', 'last_active_at', 'invited_at']);
        });
    }
};
