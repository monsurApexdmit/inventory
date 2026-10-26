<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('type', 30)->default('Shop')->after('name');
            $table->string('district', 100)->nullable()->after('address');
            $table->string('phone', 50)->nullable()->after('district');
            $table->string('manager', 150)->nullable()->after('contact_person');
            $table->boolean('sells_in_pos')->default(false)->after('is_default');
            $table->boolean('ships_online')->default(false)->after('sells_in_pos');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['type', 'district', 'phone', 'manager', 'sells_in_pos', 'ships_online']);
        });
    }
};
