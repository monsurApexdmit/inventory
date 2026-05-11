<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // gateway_type column is already string — no schema change needed.
    // This migration exists as a checkpoint; new values (stripe, paypal)
    // are allowed by the string column without modification.
    public function up(): void {}
    public function down(): void {}
};
