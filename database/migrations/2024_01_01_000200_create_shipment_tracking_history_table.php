<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id');
            $table->string('status', 100)->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('event_time')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('shipment_id')->references('id')->on('order_shipments')->onDelete('cascade');
            $table->index('shipment_id');
            $table->index('event_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_histories');
    }
};
