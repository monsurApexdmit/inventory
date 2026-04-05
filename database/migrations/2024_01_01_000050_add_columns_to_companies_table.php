<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('saas_users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeignIdFor('saas_users', 'uploaded_by');
            $table->dropColumn(['city', 'state', 'zip_code', 'description', 'uploaded_by']);
        });
    }
};
