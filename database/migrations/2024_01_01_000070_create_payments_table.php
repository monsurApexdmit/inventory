<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('subscriptions')
                ->onDelete('cascade');
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');
            $table->bigInteger('amount'); // in cents
            $table->string('status')->default('completed'); // completed, failed, pending, refunded
            $table->string('payment_method')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('invoice_url')->nullable();
            $table->string('stripe_payment_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
