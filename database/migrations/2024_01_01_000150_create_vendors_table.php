<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('logo')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('saas_users')->nullOnDelete();
            $table->enum('status', ['Active', 'Inactive', 'Blocked'])->default('Active');
            $table->text('description')->nullable();
            $table->float('total_paid')->default(0);
            $table->float('amount_payable')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index('company_id');
            $table->unique(['company_id', 'email'], 'idx_vendor_company_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
