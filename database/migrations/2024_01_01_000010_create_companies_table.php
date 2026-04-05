<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('country')->nullable();
            $table->string('address')->nullable();
            $table->string('logo')->nullable();
            $table->string('business_type')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('currency', 10)->nullable();
            $table->string('timezone')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('status')->default('trial'); // trial, active, suspended, cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
