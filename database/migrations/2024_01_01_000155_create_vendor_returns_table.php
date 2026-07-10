<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('return_number', 100)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('vendor_name');
            $table->float('total_amount')->default(0);
            $table->enum('status', ['pending', 'shipped', 'received_by_vendor', 'completed'])->default('pending');
            $table->timestamp('return_date');
            $table->timestamp('completed_date')->nullable();
            $table->enum('credit_type', ['refund', 'credit_note', 'replacement']);
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('company_id');
            $table->index('vendor_id');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_returns');
    }
};
