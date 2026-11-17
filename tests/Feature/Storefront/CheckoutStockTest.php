<?php

namespace Tests\Feature\Storefront;

use App\Models\InventoryMovement;
use App\Models\Sell;
use App\Services\Sell\OrderLifecycleService;
use App\Services\Storefront\StorefrontOrderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Storefront\Concerns\CheckoutFixtures;
use Tests\TestCase;

/**
 * Stock rules of storefront checkout and the order lifecycle, in one process.
 * Real parallel requests are covered by CheckoutConcurrencyTest.
 */
class CheckoutStockTest extends TestCase
{
    use RefreshDatabase;
    use CheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStore();
    }

    private function lifecycle(): OrderLifecycleService
    {
        return app(OrderLifecycleService::class);
    }

    private function expectShortage(callable $fn): StorefrontOrderException
    {
        try {
            $fn();
        } catch (StorefrontOrderException $e) {
            $this->assertSame(422, $e->status);

            return $e;
        }
        $this->fail('Expected the order to be rejected for insufficient stock');
    }

    public function test_insufficient_stock_answers_422_with_line_details_and_takes_nothing(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 3]);

        $res = $this->postJson("/api/store/orders?company_id={$this->company->id}", [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'shipping_address' => ['name' => 'Buyer', 'phone' => '01700000000', 'address' => '1 Road'],
            'payment_method' => 'cod',
            'shipping_method_id' => $this->delivery->id,
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.0.product_id', $product->id)
            ->assertJsonPath('errors.0.requested_quantity', 5)
            ->assertJsonPath('errors.0.available_quantity', 3);
        $this->assertStringContainsString('Insufficient stock', $res->json('message'));
        $this->assertSame(3, $this->stockOf($product));
        $this->assertSame(0, Sell::count());
    }

    public function test_13_units_then_orders_of_10_and_5_only_the_first_is_confirmed(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);

        $first = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]]);
        $this->assertSame(3, $this->stockOf($product));

        $e = $this->expectShortage(fn () => $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]]));
        $this->assertSame(3, $e->errors[0]['available_quantity']);
        $this->assertSame(5, $e->errors[0]['requested_quantity']);
        $this->assertSame(3, $this->stockOf($product));
        $this->assertSame([$first['sell']->id], Sell::pluck('id')->all());
        $this->assertTrue((bool) $first['sell']->fresh()->stock_deducted);
    }

    public function test_one_short_line_rejects_the_whole_order_without_partial_deduction(): void
    {
        $plenty = $this->simpleProduct([$this->warehouseA->id => 10]);
        $scarce = $this->simpleProduct([$this->warehouseA->id => 2]);

        $e = $this->expectShortage(fn () => $this->placeOrder([
            ['product_id' => $plenty->id, 'quantity' => 5],
            ['product_id' => $scarce->id, 'quantity' => 3],
        ]));

        $this->assertCount(1, $e->errors);
        $this->assertSame($scarce->id, $e->errors[0]['product_id']);
        $this->assertSame(10, $this->stockOf($plenty));
        $this->assertSame(2, $this->stockOf($scarce));
        $this->assertSame(0, Sell::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_repeated_cart_lines_are_added_up_before_checking(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 5]);

        $this->expectShortage(fn () => $this->placeOrder([
            ['product_id' => $product->id, 'quantity' => 3],
            ['product_id' => $product->id, 'quantity' => 3],
        ]));
        $this->assertSame(5, $this->stockOf($product));
    }

    public function test_failure_after_stock_was_taken_rolls_everything_back(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        Sell::updating(function (Sell $sell) {
            if ($sell->stock_deducted) {
                throw new \RuntimeException('Simulated crash after the stock was taken');
            }
        });

        try {
            $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]]);
            $this->fail('The simulated crash should surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated crash after the stock was taken', $e->getMessage());
        }

        $this->assertSame(13, $this->stockOf($product));
        $this->assertSame(13, (int) $product->fresh()->stock);
        $this->assertSame(0, Sell::withTrashed()->count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_only_ships_online_locations_are_used_and_lines_split_across_them(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 4, $this->warehouseB->id => 5, $this->shop->id => 100]);

        $this->placeOrder([['product_id' => $product->id, 'quantity' => 7]]);
        $this->assertSame(2, $this->stockOf($product, $this->warehouseA->id) + $this->stockOf($product, $this->warehouseB->id));
        $this->assertSame(100, $this->stockOf($product, $this->shop->id));

        $e = $this->expectShortage(fn () => $this->placeOrder([['product_id' => $product->id, 'quantity' => 3]]));
        $this->assertSame(2, $e->errors[0]['available_quantity']);
        $this->assertSame(100, $this->stockOf($product, $this->shop->id));
    }

    public function test_pickup_orders_only_use_the_pickup_point(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 50, $this->pickupPoint->id => 1]);

        $e = $this->expectShortage(fn () => $this->placeOrder(
            [['product_id' => $product->id, 'quantity' => 2]],
            [],
            ['shipping_method_id' => null, 'pickup_location_id' => $this->pickupPoint->id]
        ));
        $this->assertSame(1, $e->errors[0]['available_quantity']);
        $this->assertSame(50, $this->stockOf($product, $this->warehouseA->id));
    }

    public function test_variants_never_borrow_stock_from_each_other(): void
    {
        [$product, $v] = $this->variantProduct(['Red' => [$this->warehouseA->id => 2], 'Blue' => [$this->warehouseA->id => 10]]);

        $e = $this->expectShortage(fn () => $this->placeOrder([['product_id' => $product->id, 'variant_id' => $v['Red']->id, 'quantity' => 3]]));
        $this->assertSame($v['Red']->id, $e->errors[0]['variant_id']);
        $this->assertSame(2, $e->errors[0]['available_quantity']);

        $this->placeOrder([['product_id' => $product->id, 'variant_id' => $v['Blue']->id, 'quantity' => 3]]);
        $this->assertSame(2, $this->stockOf($v['Red']));
        $this->assertSame(7, $this->stockOf($v['Blue']));
        $this->assertSame(9, (int) $product->fresh()->stock);
    }

    public function test_legacy_flat_stock_is_checked_and_never_goes_negative(): void
    {
        $product = $this->flatProduct(13);

        $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]]);
        $this->assertSame(3, (int) $product->fresh()->stock);

        $e = $this->expectShortage(fn () => $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]]));
        $this->assertSame(3, $e->errors[0]['available_quantity']);
        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    public function test_same_idempotency_key_returns_the_first_order_and_takes_stock_once(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);

        $first = $this->placeOrder([['product_id' => $product->id, 'quantity' => 4]], ['idempotency_key' => 'checkout-abc']);
        $again = $this->placeOrder([['product_id' => $product->id, 'quantity' => 4]], ['idempotency_key' => 'checkout-abc']);

        $this->assertSame($first['sell']->id, $again['sell']->id);
        $this->assertTrue($again['replayed']);
        $this->assertSame(9, $this->stockOf($product));
        $this->assertSame(1, Sell::count());
    }

    public function test_idempotency_key_header_on_the_checkout_endpoint(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $body = [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'shipping_address' => ['name' => 'Buyer', 'phone' => '01700000000', 'address' => '1 Road'],
            'payment_method' => 'cod',
            'shipping_method_id' => $this->delivery->id,
        ];

        $a = $this->withHeader('Idempotency-Key', 'tab-1')->postJson("/api/store/orders?company_id={$this->company->id}", $body)->assertCreated();
        $b = $this->withHeader('Idempotency-Key', 'tab-1')->postJson("/api/store/orders?company_id={$this->company->id}", $body)->assertCreated();

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(11, $this->stockOf($product));
    }

    public function test_duplicate_payment_confirmations_change_nothing_the_second_time(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->awaitingPayment($this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell']);

        $this->assertTrue($this->lifecycle()->markPaid($sell->id, 'TXN-1'));
        $this->assertTrue($this->lifecycle()->markPaid($sell->id, 'TXN-2'));

        $sell->refresh();
        $this->assertSame('paid', $sell->payment_status);
        $this->assertSame('TXN-1', $sell->payment_transaction_id);
        $this->assertNull($sell->stock_hold_expires_at);
        $this->assertSame(8, $this->stockOf($product));
        $this->assertSame(-5, $this->saleMovementsTotal($sell));
    }

    public function test_failed_payment_cancels_the_order_and_releases_stock_once(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->awaitingPayment($this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell']);

        $this->lifecycle()->markPaymentFailed($sell->id, 'failed', OrderLifecycleService::REASON_PAYMENT_FAILED);
        $this->lifecycle()->markPaymentFailed($sell->id, 'failed', OrderLifecycleService::REASON_PAYMENT_FAILED);
        $this->lifecycle()->cancel($sell->id, 'Cancelled by staff');

        $sell->refresh();
        $this->assertSame('Cancelled', $sell->status);
        $this->assertSame('failed', $sell->payment_status);
        $this->assertSame(OrderLifecycleService::REASON_PAYMENT_FAILED, $sell->cancel_reason);
        $this->assertFalse((bool) $sell->stock_deducted);
        $this->assertSame(13, $this->stockOf($product));
        $this->assertSame(0, $this->saleMovementsTotal($sell));
    }

    public function test_a_late_failure_never_overrides_a_payment(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->awaitingPayment($this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell']);

        $this->lifecycle()->markPaid($sell->id, 'TXN-1');
        $this->lifecycle()->markPaymentFailed($sell->id, 'failed', OrderLifecycleService::REASON_PAYMENT_FAILED);

        $sell->refresh();
        $this->assertSame('paid', $sell->payment_status);
        $this->assertSame('Pending', $sell->status);
        $this->assertSame(8, $this->stockOf($product));
    }

    public function test_cancelling_twice_restores_stock_once(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]])['sell'];

        $this->assertTrue($this->lifecycle()->cancel($sell->id, 'Cancelled by staff'));
        $this->assertFalse($this->lifecycle()->cancel($sell->id, 'Cancelled by staff'));

        $this->assertSame(13, $this->stockOf($product));
    }

    public function test_cancelling_a_legacy_flat_stock_order_restores_once(): void
    {
        $product = $this->flatProduct(13);
        $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]])['sell'];

        $this->lifecycle()->cancel($sell->id, 'Cancelled by staff');
        $this->lifecycle()->cancel($sell->id, 'Cancelled by staff');

        $this->assertSame(13, (int) $product->fresh()->stock);
    }

    public function test_expired_holds_are_released_once_and_open_holds_are_kept(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $expired = $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell'];
        $expired->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);
        $open = $this->awaitingPayment($this->placeOrder([['product_id' => $product->id, 'quantity' => 3]])['sell']);
        $this->assertSame(5, $this->stockOf($product));

        $this->assertSame(1, $this->lifecycle()->releaseExpiredHolds());
        $this->assertSame(0, $this->lifecycle()->releaseExpiredHolds());

        $this->assertSame(10, $this->stockOf($product));
        $this->assertSame('Cancelled', $expired->fresh()->status);
        $this->assertSame('expired', $expired->fresh()->payment_status);
        $this->assertSame(OrderLifecycleService::REASON_HOLD_EXPIRED, $expired->fresh()->cancel_reason);
        $this->assertSame('Pending', $open->fresh()->status);
    }

    public function test_release_expired_command_runs(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell'];
        $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);

        $this->artisan('orders:release-expired')->expectsOutput('Released 1 expired order(s).')->assertSuccessful();
        $this->assertSame(13, $this->stockOf($product));
    }

    public function test_payment_after_expiry_takes_the_stock_again_when_it_is_still_there(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]])['sell'];
        $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);
        $this->lifecycle()->releaseExpiredHolds();
        $this->assertSame(13, $this->stockOf($product));

        $this->assertTrue($this->lifecycle()->markPaid($sell->id, 'TXN-LATE'));

        $sell->refresh();
        $this->assertSame('Pending', $sell->status);
        $this->assertSame('paid', $sell->payment_status);
        $this->assertTrue((bool) $sell->stock_deducted);
        $this->assertNull($sell->cancel_reason);
        $this->assertSame(8, $this->stockOf($product));
        $this->assertSame(-5, $this->saleMovementsTotal($sell));
    }

    public function test_payment_after_expiry_with_the_stock_gone_is_flagged_for_refund(): void
    {
        $product = $this->simpleProduct([$this->warehouseA->id => 13]);
        $sell = $this->placeOrder([['product_id' => $product->id, 'quantity' => 10]])['sell'];
        $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->subMinute()]);
        $this->lifecycle()->releaseExpiredHolds();
        $this->placeOrder([['product_id' => $product->id, 'quantity' => 5]]); // someone else buys in the meantime

        $this->assertFalse($this->lifecycle()->markPaid($sell->id, 'TXN-LATE'));

        $sell->refresh();
        $this->assertSame('Cancelled', $sell->status);
        $this->assertSame('paid', $sell->payment_status);
        $this->assertFalse((bool) $sell->stock_deducted);
        $this->assertStringContainsString('Refund', $sell->cancel_reason);
        $this->assertSame(8, $this->stockOf($product));
        $this->assertDatabaseHas('notifications', ['company_id' => $this->company->id, 'title' => 'Refund needed']);
    }

    public function test_bundle_orders_take_components_and_are_limited_by_them(): void
    {
        $shirt = $this->simpleProduct([$this->warehouseA->id => 5]);
        $cap = $this->simpleProduct([$this->warehouseA->id => 2]);
        $bundle = $this->baseProduct(['is_bundle' => true]);
        \App\Models\ProductBundleItem::create(['bundle_product_id' => $bundle->id, 'product_id' => $shirt->id, 'quantity' => 2]);
        \App\Models\ProductBundleItem::create(['bundle_product_id' => $bundle->id, 'product_id' => $cap->id, 'quantity' => 1]);

        $e = $this->expectShortage(fn () => $this->placeOrder([['product_id' => $bundle->id, 'quantity' => 3]]));
        $this->assertSame(2, $e->errors[0]['available_quantity']);

        $this->placeOrder([['product_id' => $bundle->id, 'quantity' => 2]]);
        $this->assertSame(1, $this->stockOf($shirt));
        $this->assertSame(0, $this->stockOf($cap));
    }
}
