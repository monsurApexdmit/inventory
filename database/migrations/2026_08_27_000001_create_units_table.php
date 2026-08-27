<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Units of measurement (pcs, kg, litre, box…), company-scoped. Products
// reference a unit via products.unit_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('unit_name', 100);
            $table->string('symbol', 20)->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
