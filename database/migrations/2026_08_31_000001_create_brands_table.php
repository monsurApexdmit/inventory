<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Product brands (Nike, Apple…), company-scoped. Products reference a brand
// via products.brand_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('brand_name', 150);
            $table->string('logo')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
