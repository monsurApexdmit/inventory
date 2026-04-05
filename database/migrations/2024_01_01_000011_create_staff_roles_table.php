<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stub table — full columns added in Phase 2 (staff-roles module).
// Created here only so saas_users.role_id FK can reference it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'idx_staffrole_company_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_roles');
    }
};
