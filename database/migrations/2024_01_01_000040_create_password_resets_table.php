<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('saas_users')->cascadeOnDelete();
            $table->string('email', 255)->index();
            $table->string('reset_token', 255)->unique();
            $table->timestamp('expires_at');
            $table->string('status')->default('pending'); // pending, used, expired
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
