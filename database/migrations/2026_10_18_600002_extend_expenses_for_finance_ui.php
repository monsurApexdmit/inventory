<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('vendor_id')->constrained('locations')->nullOnDelete();
            $table->string('paid_to')->nullable()->after('title');
            $table->date('next_due_date')->nullable()->after('recurring_end_date');
            $table->string('source')->default('manual')->after('next_due_date');
            $table->unsignedBigInteger('salary_payment_id')->nullable()->after('source');
            $table->index(['company_id', 'source']);
            $table->index('next_due_date');
        });

        // recurring_frequency was an enum(weekly, monthly, yearly); now Week|Month|Quarter|Year.
        DB::statement('ALTER TABLE expenses MODIFY recurring_frequency VARCHAR(20) NULL');
        DB::table('expenses')->where('recurring_frequency', 'weekly')->update(['recurring_frequency' => 'Week']);
        DB::table('expenses')->where('recurring_frequency', 'monthly')->update(['recurring_frequency' => 'Month']);
        DB::table('expenses')->where('recurring_frequency', 'yearly')->update(['recurring_frequency' => 'Year']);

        // Existing recurring bills get a next due date one period after their expense date.
        $map = ['Week' => '7 DAY', 'Month' => '1 MONTH', 'Year' => '1 YEAR'];
        foreach ($map as $freq => $interval) {
            DB::statement("UPDATE expenses SET next_due_date = DATE_ADD(expense_date, INTERVAL {$interval}) WHERE is_recurring = 1 AND recurring_frequency = ? AND next_due_date IS NULL", [$freq]);
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['company_id', 'source']);
            $table->dropIndex(['next_due_date']);
            $table->dropColumn(['location_id', 'paid_to', 'next_due_date', 'source', 'salary_payment_id']);
        });
    }
};
