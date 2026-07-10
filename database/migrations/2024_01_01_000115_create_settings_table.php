<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->json('general_settings')->nullable();
            $table->json('tax_settings')->nullable();
            $table->json('shipping_settings')->nullable();
            $table->json('payment_settings')->nullable();
            $table->json('business_settings')->nullable();
            $table->json('regional_settings')->nullable();
            $table->json('notification_settings')->nullable();
            $table->json('store_hours')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('saas_users')->setNullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
