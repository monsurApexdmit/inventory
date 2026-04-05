<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->unique()
                ->constrained('companies')
                ->onDelete('cascade');
            $table->string('company_name');
            $table->string('tax_id')->nullable();
            $table->string('tax_id_type')->nullable();
            $table->float('tax_rate')->default(0);
            $table->string('currency')->default('USD');
            $table->string('timezone')->default('UTC');
            $table->string('language')->default('en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
