<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('name');
            $table->string('district')->nullable();
            $table->string('country')->nullable();
            $table->string('payment_terms')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->string('tin')->nullable();
            $table->string('pay_to')->nullable();
            $table->string('account_number')->nullable();
            $table->text('notes')->nullable();
        });

        // email becomes optional (the (company_id, email) unique index tolerates multiple NULLs).
        DB::statement('ALTER TABLE vendors MODIFY email VARCHAR(255) NULL');
        DB::statement('ALTER TABLE vendors MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 50)->default('cash');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'district', 'country', 'payment_terms', 'opening_balance', 'tin', 'pay_to', 'account_number', 'notes']);
        });
    }
};
