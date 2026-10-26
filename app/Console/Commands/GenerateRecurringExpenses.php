<?php

namespace App\Console\Commands;

use App\Services\Expense\ExpenseService;
use Illuminate\Console\Command;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring';

    protected $description = 'Create expense rows for recurring bills whose next due date has arrived';

    public function handle(ExpenseService $service): int
    {
        $count = $service->generateRecurring();
        $this->info("Generated {$count} recurring expense(s).");

        return self::SUCCESS;
    }
}
