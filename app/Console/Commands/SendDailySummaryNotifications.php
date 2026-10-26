<?php

namespace App\Console\Commands;

use App\Services\Notification\DailySummaryService;
use Illuminate\Console\Command;

class SendDailySummaryNotifications extends Command
{
    protected $signature = 'notifications:daily-summary';

    protected $description = 'Create one in-app daily summary notification per company (no email/SMS is sent)';

    public function handle(DailySummaryService $service): int
    {
        $count = $service->run();
        $this->info("Created {$count} daily summary notification(s).");

        return self::SUCCESS;
    }
}
