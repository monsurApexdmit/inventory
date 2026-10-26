<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->text('areas_covered')->nullable()->after('description');
            $table->decimal('express_rate', 12, 2)->default(0)->after('price');
        });

        Schema::create('courier_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('provider', 100);
            $table->string('account_id', 150)->nullable();
            $table->text('api_key')->nullable(); // encrypted via model cast
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_accounts');
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn(['areas_covered', 'express_rate']);
        });
    }
};
