<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('return_number', 100)->unique();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 255);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number', 100)->nullable();
            $table->float('total_amount', 15, 2)->default(0);
            $table->string('status', 50)->default('pending');
            $table->timestamp('request_date');
            $table->timestamp('processed_date')->nullable();
            $table->string('refund_method', 100);
            $table->text('notes')->nullable();
            $table->string('processed_by', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->index('company_id');
            $table->index('customer_id');
            $table->index('request_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_returns');
    }
};
