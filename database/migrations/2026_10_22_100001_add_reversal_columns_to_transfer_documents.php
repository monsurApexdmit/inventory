<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_documents', 'reversal_of_id')) {
                $table->unsignedBigInteger('reversal_of_id')->nullable();
            }
            if (!Schema::hasColumn('transfer_documents', 'reversed_by_no')) {
                $table->string('reversed_by_no', 30)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfer_documents', function (Blueprint $table) {
            foreach (['reversal_of_id', 'reversed_by_no'] as $c) {
                if (Schema::hasColumn('transfer_documents', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
