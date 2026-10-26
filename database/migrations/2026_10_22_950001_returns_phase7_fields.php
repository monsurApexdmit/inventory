<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_returns') && !Schema::hasColumn('customer_returns', 'refunded_via')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->string('refunded_via', 100)->nullable()->after('refund_method');
            });
        }

        if (!Schema::hasTable('vendor_return_attachments')) {
            Schema::create('vendor_return_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->unsignedBigInteger('vendor_return_id');
                $table->string('path');
                $table->string('name');
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->timestamps();

                $table->index('vendor_return_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_return_attachments');
        if (Schema::hasColumn('customer_returns', 'refunded_via')) {
            Schema::table('customer_returns', function (Blueprint $table) {
                $table->dropColumn('refunded_via');
            });
        }
    }
};
