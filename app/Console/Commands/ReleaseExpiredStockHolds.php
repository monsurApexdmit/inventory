<?php

namespace App\Console\Commands;

use App\Services\Sell\OrderLifecycleService;
use Illuminate\Console\Command;

/**
 * Cancels online-gateway orders that were not paid within the payment hold (Payments setting
 * `payment_hold_minutes`, default 15) and puts their stock back. Safe to run concurrently and repeatedly:
 * each order is re-checked under its row lock and released at most once.
 */
class ReleaseExpiredStockHolds extends Command
{
    protected $signature = 'orders:release-expired {--limit=200 : Most orders to release per run}';

    protected $description = 'Cancel unpaid online-gateway orders whose stock hold expired and release their stock';

    public function handle(OrderLifecycleService $orders): int
    {
        $released = $orders->releaseExpiredHolds(max(1, (int) $this->option('limit')));
        $this->info("Released {$released} expired order(s).");

        return self::SUCCESS;
    }
}
