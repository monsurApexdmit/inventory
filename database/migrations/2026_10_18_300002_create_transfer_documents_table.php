<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('transfer_no', 30);
            $table->unsignedBigInteger('from_location_id');
            $table->unsignedBigInteger('to_location_id');
            $table->string('reason', 255)->nullable();
            $table->string('sent_with', 255)->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('completed'); // completed|cancelled
            $table->integer('units')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('from_location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('to_location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->unique(['company_id', 'transfer_no']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_document_id')->nullable()->after('company_id');
            $table->foreign('transfer_document_id')->references('id')->on('transfer_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['transfer_document_id']);
            $table->dropColumn('transfer_document_id');
        });
        Schema::dropIfExists('transfer_documents');
    }
};
