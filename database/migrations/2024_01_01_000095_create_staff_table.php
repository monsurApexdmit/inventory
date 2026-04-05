<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->string('name');
            $table->string('email');
            $table->string('contact')->nullable();
            $table->string('joining_date')->nullable();
            $table->string('role')->nullable();
            $table->string('status')->default('Active'); // Active, Inactive
            $table->boolean('published')->default(false);
            $table->string('avatar')->nullable();
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('saas_users')
                ->onDelete('set null');
            $table->float('salary')->default(0);
            $table->string('bank_account')->nullable();
            $table->string('payment_method')->nullable(); // Bank Transfer, Cash, Check
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
