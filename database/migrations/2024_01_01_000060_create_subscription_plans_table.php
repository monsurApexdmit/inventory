<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price')->default(0); // in cents
            $table->string('billing_period')->default('monthly'); // monthly, yearly
            $table->integer('max_users')->default(10);
            $table->integer('max_products')->default(1000);
            $table->integer('max_branches')->default(1);
            $table->text('features')->nullable(); // JSON serialized
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
