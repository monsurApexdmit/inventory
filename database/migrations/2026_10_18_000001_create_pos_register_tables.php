<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('register', 100);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('session_no', 30);
            $table->string('status', 10)->default('open');
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->string('opened_by', 150)->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->text('close_note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('register_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_session_id')->constrained('register_sessions')->cascadeOnDelete();
            $table->string('type', 3);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->string('created_by', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('label', 50);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('payload');
            $table->string('created_by', 150)->nullable();
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::table('sells', function (Blueprint $table) {
            $table->unsignedBigInteger('register_session_id')->nullable()->after('location_id');
            $table->index('register_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('sells', function (Blueprint $table) {
            $table->dropIndex(['register_session_id']);
            $table->dropColumn('register_session_id');
        });

        Schema::dropIfExists('held_sales');
        Schema::dropIfExists('register_cash_movements');
        Schema::dropIfExists('register_sessions');
    }
};
