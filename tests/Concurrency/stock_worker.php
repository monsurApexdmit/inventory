<?php

/**
 * Worker for CheckoutConcurrencyTest: boots the app in its own PHP process (own MySQL connection), waits for a
 * shared start time so all workers hit the database together, runs one action and prints one JSON line.
 *
 *   php tests/Concurrency/stock_worker.php '{"action":"place","company_id":1,"items":[...],"start_at":1700000000.5}'
 *
 * Actions: place (checkout), cancel, fail (payment failed callback), paid (payment success callback), release (expiry job).
 */

use App\Services\Sell\OrderLifecycleService;
use App\Services\Storefront\StorefrontCheckoutService;
use App\Services\Storefront\StorefrontOrderException;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$job = json_decode($argv[1] ?? '{}', true, 512, JSON_THROW_ON_ERROR);

$database = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Refusing to run against database '{$database}'\n");
    exit(2);
}

DB::connection()->getPdo(); // connect before the barrier so every worker starts its query at the same moment
while (microtime(true) < (float) $job['start_at']) {
    usleep(200);
}

$out = ['ok' => true];
try {
    switch ($job['action']) {
        case 'place':
            $placed = app(StorefrontCheckoutService::class)->place((int) $job['company_id'], [
                'items' => $job['items'],
                'shipping_address' => ['name' => 'Buyer', 'phone' => '01700000000', 'address' => '1 Road'],
                'payment_method' => 'cod',
                'shipping_method_id' => $job['shipping_method_id'],
            ], ['customer_name' => 'Buyer ' . getmypid(), 'idempotency_key' => $job['idempotency_key'] ?? null]);
            $out['sell_id'] = (int) $placed['sell']->id;
            $out['replayed'] = (bool) ($placed['replayed'] ?? false);
            break;
        case 'cancel':
            $out['changed'] = app(OrderLifecycleService::class)->cancel((int) $job['sell_id'], 'Cancelled by staff');
            break;
        case 'fail':
            app(OrderLifecycleService::class)->markPaymentFailed((int) $job['sell_id'], 'failed', OrderLifecycleService::REASON_PAYMENT_FAILED);
            break;
        case 'paid':
            $out['holds_stock'] = app(OrderLifecycleService::class)->markPaid((int) $job['sell_id'], 'TXN-' . getmypid());
            break;
        case 'release':
            $out['released'] = app(OrderLifecycleService::class)->releaseExpiredHolds();
            break;
        default:
            throw new InvalidArgumentException('Unknown action ' . $job['action']);
    }
} catch (StorefrontOrderException $e) {
    $out = ['ok' => false, 'status' => $e->status, 'message' => $e->getMessage(), 'errors' => $e->errors];
} catch (Throwable $e) {
    $out = ['ok' => false, 'status' => 500, 'message' => get_class($e) . ': ' . $e->getMessage()];
}

echo json_encode($out), PHP_EOL;
