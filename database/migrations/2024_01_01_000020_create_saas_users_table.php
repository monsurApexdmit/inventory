<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('full_name');
            $table->string('password');
            $table->string('role')->default('staff'); // owner, admin, manager, staff
            $table->foreignId('role_id')->nullable()->constrained('staff_roles')->nullOnDelete();
            $table->string('status')->default('unverified'); // unverified, active, invited, inactive
            $table->timestamp('joined_date')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->string('avatar')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('saas_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'email'], 'idx_company_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_users');
    }
};
