<?php

namespace Tests\Feature\Storefront;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sell;
use App\Models\VariantInventory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Feature\Storefront\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Real concurrency on MySQL: every buyer is a separate PHP process with its own database connection
 * (tests/Concurrency/stock_worker.php). Workers boot, then all start their checkout at the same instant,
 * so their transactions genuinely compete for the same row locks.
 *
 * Data must be committed for other connections to see it, so this class truncates instead of wrapping
 * each test in a transaction.
 */
class CheckoutConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use CheckoutFixtures;

    /** Rounds per race: each round is a fresh product and a fresh race. */
    private const ROUNDS = 5;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Row-lock tests need MySQL.');
        }
        $this->setUpStore();
    }

    /** The workers committed real rows: clear them so later (transaction-wrapped) test classes start clean. */
    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql') {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_13_units_and_racing_orders_of_10_and_5_confirm_only_one(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $product = $this->simpleProduct([$this->warehouseA->id => 13]);

            $results = $this->race([
                $this->placeJob([['product_id' => $product->id, 'quantity' => 10]]),
                $this->placeJob([['product_id' => $product->id, 'quantity' => 5]]),
            ]);

            $won = array_values(array_filter($results, fn ($r) => $r['ok']));
            $lost = array_values(array_filter($results, fn ($r) => !$r['ok']));
            $this->assertCount(1, $won, "Round {$round}: exactly one order must be confirmed. " . json_encode($results));
            $this->assertCount(1, $lost);
            $this->assertSame(422, $lost[0]['status'], json_encode($lost[0]));

            $left = $this->stockOf($product);
            $this->assertContains($left, [3, 8], "Round {$round}: 13 - winner's quantity");
            // The loser saw exactly what the winner left behind
            $this->assertSame($left, $lost[0]['errors'][0]['available_quantity']);
            $this->assertSame(13 - $left, -(int) InventoryMovement::where('product_id', $product->id)->where('reference_type', 'sell')->sum('quantity'));
            $this->assertSame(1, Sell::whereIn('id', array_column($won, 'sell_id'))->count());
        }
    }

    public function test_orders_that_fit_together_both_succeed(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $product = $this->simpleProduct([$this->warehouseA->id => 13]);

            $results = $this->race([
                $this->placeJob([['product_id' => $product->id, 'quantity' => 6]]),
                $this->placeJob([['product_id' => $product->id, 'quantity' => 5]]),
            ]);

            $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
            $this->assertSame(2, $this->stockOf($product));
        }
    }

    public function test_many_buyers_never_oversell_or_go_negative(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 7, $this->warehouseB->id => 6]);

        $results = $this->race(array_fill(0, 10, $this->placeJob([['product_id' => $product->id, 'quantity' => 2]])));

        $this->assertSame(6, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(1, $this->stockOf($product));
        $this->assertSame(0, VariantInventory::where('quantity', '<', 0)->count());
        $this->assertSame(1, (int) $product->fresh()->stock);
    }

    public function test_carts_with_the_same_products_in_opposite_order_do_not_deadlock(): void
    {
        $shirt = $this->simpleProduct([$this->warehouseA->id => 20]);
        $jeans = $this->simpleProduct([$this->warehouseA->id => 20]);
        $forward = $this->placeJob([['product_id' => $shirt->id, 'quantity' => 2], ['product_id' => $jeans->id, 'quantity' => 3]]);
        $backward = $this->placeJob([['product_id' => $jeans->id, 'quantity' => 3], ['product_id' => $shirt->id, 'quantity' => 2]]);

        $results = $this->race([$forward, $backward, $forward, $backward, $forward, $backward]);

        $this->assertSame(array_fill(0, 6, true), array_column($results, 'ok'), json_encode($results));
        $this->assertSame(8, $this->stockOf($shirt));
        $this->assertSame(2, $this->stockOf($jeans));
    }

    public function test_variants_racing_for_the_same_product_only_compete_per_variant(): void
    {
        [$product, $v] = $this->variantProduct(['S' => [$this->warehouseA->id => 3], 'L' => [$this->warehouseA->id => 3]]);

        $results = $this->race([
            $this->placeJob([['product_id' => $product->id, 'variant_id' => $v['S']->id, 'quantity' => 2]]),
            $this->placeJob([['product_id' => $product->id, 'variant_id' => $v['S']->id, 'quantity' => 2]]),
            $this->placeJob([['product_id' => $product->id, 'variant_id' => $v['L']->id, 'quantity' => 3]]),
        ]);

        $this->assertSame(1, count(array_filter(array_slice($results, 0, 2), fn ($r) => $r['ok'])), json_encode($results));
        $this->assertTrue($results[2]['ok'], json_encode($results[2]));
        $this->assertSame(1, $this->stockOf($v['S']));
        $this->assertSame(0, $this->stockOf($v['L']));
    }

    public function test_double_submitted_checkout_creates_one_order(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $job = $this->placeJob([['product_id' => $product->id, 'quantity' => 4]]) + ['idempotency_key' => 'same-click'];

        $results = $this->race([$job, $job, $job]);

        $this->assertSame([true, true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'sell_id')));
        $this->assertSame(1, Sell::count());
        $this->assertSame(9, $this->stockOf($product));
    }

    public function test_duplicate_payment_callbacks_racing_take_nothing_extra(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->awaitingPayment($this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell']);

        $results = $this->race(array_fill(0, 4, ['action' => 'paid', 'sell_id' => $sell->id]));

        $this->assertSame([true, true, true, true], array_column($results, 'ok'), json_encode($results));
        $this->assertSame('paid', $sell->fresh()->payment_status);
        $this->assertSame(8, $this->stockOf($product));
        $this->assertSame(-5, $this->saleMovementsTotal($sell));
    }

    public function test_cancel_payment_failure_and_expiry_racing_release_stock_exactly_once(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $product = $this->simpleProduct([$this->warehouseA->id => 13]);
            $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]])['sell'];
            $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);

            $results = $this->race([
                ['action' => 'cancel', 'sell_id' => $sell->id],
                ['action' => 'fail', 'sell_id' => $sell->id],
                ['action' => 'release'],
                ['action' => 'cancel', 'sell_id' => $sell->id],
            ]);

            $this->assertSame([true, true, true, true], array_column($results, 'ok'), json_encode($results));
            $this->assertSame(13, $this->stockOf($product), "Round {$round}: stock returned exactly once");
            $this->assertSame(0, $this->saleMovementsTotal($sell));
            $this->assertSame('Cancelled', $sell->fresh()->status);
        }
    }

    public function test_payment_racing_expiry_ends_consistent(): void
    {
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $product = $this->simpleProduct([$this->warehouseA->id => 13]);
            $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]])['sell'];
            $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);

            $this->race([['action' => 'paid', 'sell_id' => $sell->id], ['action' => 'release']]);

            // Whichever came first, a paid order ends holding its stock exactly once
            $sell->refresh();
            $this->assertSame('paid', $sell->payment_status);
            $this->assertSame('Pending', $sell->status);
            $this->assertTrue((bool) $sell->stock_deducted);
            $this->assertSame(3, $this->stockOf($product));
            $this->assertSame(-10, $this->saleMovementsTotal($sell));
        }
    }

    // ------------------------------------------------------------------

    private function placeJob(array $items): array
    {
        return [
            'action' => 'place',
            'company_id' => $this->company->id,
            'shipping_method_id' => $this->delivery->id,
            'items' => $items,
        ];
    }

    /**
     * Start one worker process per job, release them together, return their results in job order.
     *
     * @return list<array>
     */
    private function race(array $jobs): array
    {
        $startAt = microtime(true) + 2.5 + 0.15 * count($jobs); // time for every worker to boot and connect
        $env = $this->workerEnv();
        $procs = [];
        foreach ($jobs as $i => $job) {
            $job['start_at'] = $startAt;
            $procs[$i] = proc_open(
                [PHP_BINARY, base_path('tests/Concurrency/stock_worker.php'), json_encode($job)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
                $env
            );
            $this->assertIsResource($procs[$i], 'Could not start a worker process');
        }

        $results = [];
        foreach ($procs as $i => $proc) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $code = proc_close($proc);
            $lines = array_values(array_filter(explode("\n", trim((string) $stdout))));
            $decoded = $lines ? json_decode(end($lines), true) : null;
            $this->assertIsArray($decoded, "Worker {$i} exited {$code} without a result.\nstdout: {$stdout}\nstderr: {$stderr}");
            $this->assertNotSame(500, $decoded['status'] ?? null, "Worker {$i} crashed: " . ($decoded['message'] ?? ''));
            $results[$i] = $decoded;
        }
        ksort($results);

        return array_values($results);
    }

    /** Same database and drivers as this test process (phpunit.xml), whatever the container's own env says. */
    private function workerEnv(): array
    {
        $db = config('database.connections.' . config('database.default'));

        return array_merge(array_filter(getenv(), 'is_string'), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ]);
    }
}
