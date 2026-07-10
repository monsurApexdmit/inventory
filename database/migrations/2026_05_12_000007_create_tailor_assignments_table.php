<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('dorji_id');
            $table->date('assigned_date');
            $table->date('expected_completion')->nullable();
            $table->decimal('dorji_charge', 10, 2)->default(0);
            $table->enum('work_status', ['assigned', 'in_progress', 'completed', 'returned'])->default('assigned');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'order_id']);
            $table->index('dorji_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_assignments');
    }
};
