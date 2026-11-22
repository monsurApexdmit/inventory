<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\ICourierAccountRepository;
use App\Services\Courier\CourierShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the latest courier status for parcels still on the way (Pathao / Steadfast). Webhooks deliver most
 * changes instantly; this is the safety net. Errors are stored on the shipment (sync_error), never thrown.
 */
class SyncCourierShipments extends Command
{
    protected $signature = 'couriers:sync {--limit= : Most shipments per run (default config couriers.sync.batch)} {--company= : Only this company} {--stale= : Skip shipments synced in the last N minutes}';

    protected $description = 'Refresh courier (Pathao / Steadfast) status for active courier shipments';

    public function handle(ICourierAccountRepository $accounts, CourierShipmentService $service): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('couriers.sync.batch', 100)));
        $stale = max(0, (int) ($this->option('stale') ?? config('couriers.sync.stale_minutes', 10)));
        $company = $this->option('company') ? (int) $this->option('company') : null;

        $shipments = $accounts->syncCandidates(now()->subMinutes($stale), $limit, $company);
        $changed = 0;
        $failed = 0;
        foreach ($shipments as $shipment) {
            try {
                if ($service->syncShipment($shipment)) {
                    $changed++;
                }
                if ($shipment->fresh()?->sync_error) {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('couriers:sync failed for a shipment', ['shipment' => $shipment->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Checked {$shipments->count()} courier shipment(s): {$changed} changed, {$failed} with errors.");

        return self::SUCCESS;
    }
}
