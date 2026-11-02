<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module: money accounts (cash, bank, wallets), manual ledger entries and transfers
 * between accounts. Everything else in the ledger is computed from existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('finance_accounts')) {
            Schema::create('finance_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name', 100);
                $table->string('type', 20)->default('cash'); // cash|bank|mobile_wallet|card|other
                $table->json('method_keys')->nullable();       // payment-method keys that post here
                $table->decimal('opening_balance', 15, 2)->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('finance_entries')) {
            Schema::create('finance_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('account_id');
                $table->string('kind', 20)->default('income'); // income|expense|adjustment
                $table->enum('direction', ['in', 'out']);
                $table->decimal('amount', 15, 2);
                $table->date('date');
                $table->string('category', 100)->nullable();
                $table->string('party', 100)->nullable();
                $table->string('reference', 100)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name', 150)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'date']);
                $table->index('account_id');
            });
        }

        if (!Schema::hasTable('finance_transfers')) {
            Schema::create('finance_transfers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('from_account_id');
                $table->unsignedBigInteger('to_account_id');
                $table->decimal('amount', 15, 2);
                $table->date('date');
                $table->string('reference', 100)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name', 150)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'date']);
                $table->index('from_account_id');
                $table->index('to_account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transfers');
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_accounts');
    }
};
