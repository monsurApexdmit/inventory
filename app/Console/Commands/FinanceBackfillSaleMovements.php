<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceStockService;
use Illuminate\Console\Command;

/** INV-2: create the missing `sell` stock movements for historic sale lines (idempotent; stock is not changed). */
class FinanceBackfillSaleMovements extends Command
{
    protected $signature = 'finance:backfill-sale-movements {company : Company id} {--dry-run : Only report what would be created}';

    protected $description = 'Create missing sale stock movements for historic sale lines (use --dry-run first)';

    public function handle(FinanceStockService $stock): int
    {
        $cid = (int) $this->argument('company');
        $dry = (bool) $this->option('dry-run');
        $res = $stock->backfillSaleMovements($cid, $dry);
        $this->info(($dry ? '[dry run] ' : '') . "Company {$cid}: {$res['lines']} sale line(s) on {$res['sales']} sale(s) without a stock movement; quantity {$res['quantity']}, value {$res['value']}.");
        $this->info($dry ? 'Nothing was written.' : "Created {$res['created']} movement(s).");

        return self::SUCCESS;
    }
}
