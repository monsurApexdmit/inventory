<?php

namespace App\Services\Storefront;

use App\Http\Controllers\Api\Storefront\StorefrontOrderController;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sell;
use App\Models\Setting;
use App\Services\Coupon\CouponService;
use App\Services\Gateway\BkashService;
use App\Services\Gateway\NagadService;
use App\Services\Gateway\PayPalService;
use App\Services\Gateway\PortWalletService;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\StripeService;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\LocationStockService;
use App\Services\Inventory\StockReservationService;
use App\Services\Notification\NotificationService;
use App\Services\Pricing\PriceService;
use App\Services\Sell\SellService;
use App\Services\Shipping\ShippingMethodService;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Places a storefront order. Shared by the normal checkout (POST /store/orders) and the
 * quick order form (POST /store/quick-order) so pricing (PriceService), coupons, server-side
 * delivery charge, payment-method rules, COD limit/fee, location stock and gateways stay identical.
 *
 * Stock is checked and taken inside one transaction under row locks (StockReservationService), so concurrent
 * checkouts for the same stock are served one after another and the later one sees what is left.
 */
class StorefrontCheckoutService
{
    use DetectsConcurrencyErrors;

    /** Payment methods that redirect the buyer to an online gateway (stock is held until paid). */
    private const GATEWAYS = ['sslcommerz', 'portwallet', 'stripe', 'paypal', 'bkash', 'nagad'];

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly CouponService $couponService,
        private readonly StockReservationService $reservations,
    ) {
    }

    /**
     * @param array $input  Validated checkout input (snake_case, as POST /store/orders):
     *                      items[{product_id, variant_id?, quantity}], shipping_address{name, phone?, email?, address, address2?,
     *                      city?, state?, zip?, country?}, payment_method, shipping_method_id?, pickup_location_id?, coupon_code?,
     *                      transaction_id?, note?
     * @param array $ctx    customer_id?, customer_name, customer_email?, attribution? (sells columns from AttributionService),
     *                      is_quick_order?, possible_duplicate?, require_variant? (bool), campaign_link_name?,
     *                      idempotency_key? (one order per key; a repeat returns the first order, see replay())
     * @return array{sell: Sell, payment_url: ?string, deposit_only: bool, deposit_amount: ?float, subtotal: float,
     *               discount: float, shipping_cost: float, cod_fee: float, gateway_type: string}
     *
     * @throws StorefrontOrderException
     */
    public function place(int $companyId, array $input, array $ctx): array
    {
        $items = $input['items'];
        $customerId = $ctx['customer_id'] ?? null;
        $customerName = $ctx['customer_name'];
        $customerEmail = $ctx['customer_email'] ?? null;
        $requireVariant = (bool) ($ctx['require_variant'] ?? false);
        $idempotencyKey = isset($ctx['idempotency_key']) && trim((string) $ctx['idempotency_key']) !== ''
            ? mb_substr(trim((string) $ctx['idempotency_key']), 0, 100) : null;

        // Repeat of a checkout that already went through: return that order, take nothing again
        if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($companyId, $idempotencyKey))) {
            return $this->replay($existing);
        }

        $orderItems = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $product = Product::where('company_id', $companyId)
                ->where('published', true)
                ->find($item['product_id']);

            if (!$product) {
                throw new StorefrontOrderException("Product ID {$item['product_id']} not found");
            }

            // Normal price, the live offer price (expired offers price at normal) or the wholesale price from the minimum quantity
            $variantModel = !empty($item['variant_id'])
                ? ProductVariant::where('product_id', $product->id)->find($item['variant_id'])
                : null;
            if (!empty($item['variant_id']) && !$variantModel) {
                throw new StorefrontOrderException('That option is not available for this product.', 422, ['variant_id' => ['That option is not available for this product.']]);
            }
            if ($requireVariant && !$variantModel && $product->variants()->where('name', '<>', 'Default')->exists()) {
                throw new StorefrontOrderException('Choose an option (size / colour) for this product.', 422, ['variant_id' => ['Choose an option (size / colour) for this product.']]);
            }
            $price = PriceService::resolve($product, $variantModel, (float) $item['quantity'])['unit'];
            $lineTotal = $price * $item['quantity'];
            $subtotal += $lineTotal;

            $orderItems[] = [
                'product_id' => $product->id,
                'variant_id' => $item['variant_id'] ?? null,
                'product_name' => $product->name,
                'variant_name' => $variantModel?->name,
                'quantity' => $item['quantity'],
                'unit_price' => $price,
                'total_price' => $lineTotal,
                'unit_cost' => $product->cost_price ?? 0,
                'total_cost' => ($product->cost_price ?? 0) * $item['quantity'],
            ];
        }

        // Validate and apply coupon
        $discount = 0;
        $couponCode = null;
        $appliedCoupon = null;

        if (!empty($input['coupon_code'])) {
            try {
                $couponData = $this->couponService->validate($companyId, [
                    'code' => $input['coupon_code'],
                    'orderAmount' => $subtotal,
                    'customerId' => $customerId,
                    'customerEmail' => $customerEmail,
                    'channel' => 'online',
                    'items' => array_map(fn ($oi) => [
                        'productId' => $oi['product_id'],
                        'totalPrice' => $oi['total_price'],
                    ], $orderItems),
                ]);
                $discount = $couponData['discountAmount'] ?? 0;
                $couponCode = $input['coupon_code'];
                // Fetch the Coupon model for usage recording and free_shipping check
                $appliedCoupon = Coupon::where('company_id', $companyId)
                    ->where('code', $input['coupon_code'])
                    ->first();
            } catch (HttpException $e) {
                $decoded = json_decode($e->getMessage(), true);
                throw new StorefrontOrderException(is_array($decoded) ? ($decoded['message'] ?? 'Coupon is not valid') : $e->getMessage(), $e->getStatusCode());
            }
        }

        // Shipping is priced SERVER-SIDE from the chosen method (a client-sent shipping_cost is ignored).
        // Pickup orders are free; delivery orders must pick an active delivery option of this store.
        $shippingMethodId = $input['shipping_method_id'] ?? null;
        $hasPickupLocation = !empty($input['pickup_location_id']);
        $shippingMethodModel = !empty($shippingMethodId)
            ? \App\Models\ShippingMethod::where('company_id', $companyId)->find($shippingMethodId) : null;
        $isPickup = ($shippingMethodModel?->type === 'pickup') || $hasPickupLocation;
        $shippingCost = 0.0;
        if ($isPickup) {
            if ($shippingMethodModel && $shippingMethodModel->type !== 'pickup') {
                $shippingMethodModel = null;
            }
        } else {
            $shippingMethodModel = app(ShippingMethodService::class)->findActiveDelivery($companyId, $shippingMethodId);
            if (!$shippingMethodModel) {
                throw new StorefrontOrderException('Choose a delivery option', 422, ['shipping_method_id' => ['Choose a delivery option']]);
            }
            $shippingCost = (float) $shippingMethodModel->price;
        }
        // Free shipping coupon overrides shipping cost
        if ($appliedCoupon?->free_shipping) {
            $shippingCost = 0;
        }

        // Chosen payment method must be enabled for online orders (Payments settings), legacy gateways still work
        $codFee = 0.0;
        $paySettings = Setting::where('company_id', $companyId)->first()?->payment_settings ?? [];
        $paymentMethod = (string) $input['payment_method'];
        $selectedMethod = PaymentMethod::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower($paymentMethod)])
            ->first();
        $chosen = strtolower(trim($paymentMethod));
        $manualSlug = null;
        $savedMethods = is_array($paySettings['methods'] ?? null) ? $paySettings['methods'] : [];
        if ($savedMethods) {
            $labels = \App\Http\Requests\Setting\UpdatePaymentSettingsRequest::METHODS;
            foreach (StorefrontOrderController::onlineMethodSlugs($paySettings) as $slug) {
                if ($chosen === $slug || $chosen === strtolower($labels[$slug][0] ?? '')) {
                    $manualSlug = $slug;
                    break;
                }
            }
            $legacyGateway = $selectedMethod && $selectedMethod->is_active && ($selectedMethod->gateway_type ?? 'cod') !== 'cod';
            if (!$manualSlug && !$legacyGateway) {
                throw new StorefrontOrderException('This payment method is not available for online orders.');
            }
        }
        // 'manual' = enabled non-COD method with no gateway record (bKash/Nagad/card/bank/cash): no COD rules
        $gatewayType = $selectedMethod?->gateway_type ?? ($manualSlug && $manualSlug !== 'cod' ? 'manual' : 'cod');

        // Pickup orders collect from a chosen pickup point (an is_pickup_point location) and take stock from it
        $locStock = app(LocationStockService::class);
        $pickupLocationId = null;
        if ($isPickup) {
            if (!$hasPickupLocation) {
                throw new StorefrontOrderException('Choose a pickup point for pickup orders.');
            }
            $pickupLocationId = Location::where('company_id', $companyId)->whereKey($input['pickup_location_id'])->pickupPoints()->value('id');
            if (!$pickupLocationId) {
                throw new StorefrontOrderException('The selected pickup point is not available.');
            }
            $pickupLocationId = (int) $pickupLocationId;
        }

        // Stock comes from the pickup point, else the ships-online locations (per-location stock). Companies without
        // per-location stock keep flat product/variant stock. Both are checked and taken under row locks below.
        $onlineLocs = $locStock->storefrontLocationIds($companyId);
        $drawLocs = $pickupLocationId ? [$pickupLocationId] : $onlineLocs;
        $stockLocations = $drawLocs && $locStock->hasLocationStock($companyId) ? array_map('intval', $drawLocs) : null;
        $lines = StockReservationService::mergeLines($items);

        // Cash-on-delivery rules (Payments settings): order limit (default 5000) + extra COD fee.
        if ($gatewayType === 'cod') {
            $codLimit = (float) ($paySettings['cod_limit'] ?? 5000);
            if ($codLimit > 0 && max(0, $subtotal - $discount) >= $codLimit) {
                throw new StorefrontOrderException('Cash on delivery is only available for orders under ' . rtrim(rtrim(number_format($codLimit, 2, '.', ''), '0'), '.') . '. Please choose another payment method.');
            }
            $codFee = max(0, (float) ($paySettings['cod_fee'] ?? 0));
        }

        $total = max(0, $subtotal - $discount) + $shippingCost + $codFee;

        // Orders paid through a gateway (full amount, or the COD shipping deposit) hold their stock until the payment
        // succeeds, fails or the hold expires (payment_hold_minutes, default 15; `orders:release-expired`).
        $paysByGateway = in_array($gatewayType, self::GATEWAYS, true);
        $deposit = ($gatewayType === 'cod' && $shippingCost > 0) ? $this->depositPlan($paySettings, $shippingCost) : null;
        $awaitsPayment = $paysByGateway || $deposit !== null;
        $holdMinutes = max(5, (int) ($paySettings['payment_hold_minutes'] ?? 15));

        $addr = $input['shipping_address'];
        $attribution = is_array($ctx['attribution'] ?? null) ? $ctx['attribution'] : [];
        $txn = isset($input['transaction_id']) ? trim((string) $input['transaction_id']) : '';
        $note = isset($input['note']) ? trim((string) $input['note']) : '';

        $sellData = array_merge($attribution, [
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'invoice_no' => 'ORD-' . strtoupper(uniqid()),
            'idempotency_key' => $idempotencyKey,
            'order_time' => now(),
            'amount' => $total,
            'method' => $paymentMethod,
            'source' => 'storefront',
            'status' => 'Pending',
            'payment_status' => $awaitsPayment ? 'pending_payment' : 'pending',
            'stock_hold_expires_at' => $awaitsPayment ? now()->addMinutes($holdMinutes) : null,
            'fulfillment_status' => 'unfulfilled',
            'shipping_full_name' => $addr['name'],
            'shipping_phone' => $addr['phone'] ?? null,
            'shipping_email' => $addr['email'] ?? $customerEmail,
            'shipping_address_line1' => $addr['address'],
            'shipping_address_line2' => $addr['address2'] ?? null,
            'shipping_city' => $addr['city'] ?? null,
            'shipping_state' => $addr['state'] ?? null,
            'shipping_postal_code' => $addr['zip'] ?? null,
            'shipping_country' => $addr['country'] ?? null,
            'coupon_code' => $couponCode,
            'discount' => $discount,
            'shipping_cost' => $shippingCost,
            'shipping_method' => $shippingMethodModel?->id,
            'location_id' => $pickupLocationId,
            'transaction_id' => $txn !== '' ? mb_substr($txn, 0, 100) : null,
            'notes' => $note !== '' ? $note : null,
            'is_quick_order' => (bool) ($ctx['is_quick_order'] ?? false),
            'possible_duplicate' => (bool) ($ctx['possible_duplicate'] ?? false),
        ], $deposit ? ['shipping_deposit_amount' => $deposit['amount']] : []);

        // One short transaction: lock stock rows -> validate every line -> save order + lines -> take stock -> commit.
        // A second checkout for the same stock waits on the row locks and then sees what is left. No external
        // calls happen inside; deadlocks and lock-wait timeouts are retried (3 attempts) before answering 409.
        try {
            [$sell, $replayed] = DB::transaction(function () use ($companyId, $lines, $stockLocations, $idempotencyKey, $sellData, $orderItems, $pickupLocationId, $appliedCoupon, $customerId, $discount, $subtotal, $total): array {
                $this->reservations->lockAndValidate($companyId, $lines, $stockLocations);

                // Same checkout submitted twice: the first one committed while this one waited for the locks
                if ($idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($companyId, $idempotencyKey))) {
                    return [$existing, true];
                }

                $sell = Sell::create($sellData);
                foreach ($orderItems as $item) {
                    $sell->items()->create($item);
                }

                $byLoc = $this->reservations->deduct($companyId, $sell, $lines, $stockLocations);
                $updates = ['stock_deducted' => true];
                if (!$pickupLocationId && $byLoc) {
                    arsort($byLoc);
                    $updates['location_id'] = array_key_first($byLoc);
                }
                $sell->update($updates);

                if ($appliedCoupon) {
                    CouponUsage::create([
                        'coupon_id' => $appliedCoupon->id,
                        'customer_id' => $customerId,
                        'sell_id' => $sell->id,
                        'coupon_code' => $appliedCoupon->code,
                        'discount_applied' => $discount,
                        'original_amount' => $subtotal,
                        'final_amount' => $total,
                        'used_at' => now(),
                    ]);
                    $appliedCoupon->increment('times_used');
                }

                return [$sell, false];
            }, 3);
        } catch (InsufficientStockException $e) {
            throw new StorefrontOrderException($e->getMessage(), 422, $e->shortages);
        } catch (HttpException $e) {
            throw new StorefrontOrderException($e->getMessage(), $e->getStatusCode());
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueViolation($e)) {
                // Two identical submits raced past the check; the other one won
                $existing = $this->findByIdempotencyKey($companyId, $idempotencyKey);
                if ($existing) {
                    return $this->replay($existing);
                }
                throw new StorefrontOrderException('This checkout was already submitted.', 409);
            }
            if ($this->causedByConcurrencyError($e)) {
                throw new StorefrontOrderException('The store is busy right now. Please try placing your order again.', 409);
            }
            throw $e;
        }

        if ($replayed) {
            return $this->replay($sell);
        }

        $this->notificationService->notifyOrderPlaced($companyId, $sell->invoice_no, $customerName, $total, [
            'adSource' => $sell->ad_source,
            'isQuickOrder' => (bool) $sell->is_quick_order,
            'campaignLinkId' => $sell->campaign_link_id,
            'campaignLinkName' => $ctx['campaign_link_name'] ?? null,
        ]);

        $result = [
            'sell' => $sell,
            'payment_url' => null,
            'deposit_only' => false,
            'deposit_amount' => null,
            'subtotal' => (float) $subtotal,
            'discount' => (float) $discount,
            'shipping_cost' => (float) $shippingCost,
            'cod_fee' => (float) $codFee,
            'gateway_type' => $gatewayType,
        ];

        if (!$awaitsPayment) {
            return $result;
        }

        // Gateway HTTP calls happen after commit, with no stock locks held
        $gatewayParams = [
            'currency' => 'BDT',
            'tran_id' => 'INV-' . $sell->invoice_no,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $addr['phone'] ?? '',
            'shipping_address' => $addr['address'] ?? '',
            'shipping_city' => $addr['city'] ?? 'Dhaka',
            'brand_name' => config('app.name', 'Shop'),
        ];
        $apiBase = rtrim(config('app.url'), '/') . '/api/gateway';
        try {
            if ($paysByGateway) {
                $url = $this->initGateway($gatewayType, $companyId, $gatewayParams + [
                    'amount' => $total,
                    'product_name' => 'Order ' . $sell->invoice_no,
                ], $apiBase, $gatewayParams['tran_id']);
            } else {
                // COD shipping deposit: charge only the shipping before delivery
                $url = $this->initGateway($deposit['gateway'], $companyId, $gatewayParams + [
                    'amount' => $deposit['amount'],
                    'product_name' => 'Shipping Deposit – Order ' . $sell->invoice_no,
                ], $apiBase . '/cod-deposit', $gatewayParams['tran_id']);
                $result['deposit_only'] = true;
                $result['deposit_amount'] = $deposit['amount'];
            }
        } catch (\Throwable $e) {
            $this->discard($sell);
            throw new StorefrontOrderException($e->getMessage(), 502);
        }
        $sell->update(['payment_url' => $url]);
        $result['payment_url'] = $url;

        return $result;
    }

    /** COD shipping deposit settings when enabled and the chosen gateway has credentials, else null. */
    private function depositPlan(array $paySettings, float $shippingCost): ?array
    {
        $codDeposit = $paySettings['cod_shipping_deposit'] ?? [];
        if (!(bool) ($codDeposit['enabled'] ?? false)) {
            return null;
        }
        $gateway = $codDeposit['gateway'] ?? 'sslcommerz';
        $credentials = $paySettings[$gateway] ?? [];
        $hasCredentials = match ($gateway) {
            'sslcommerz' => !empty($credentials['store_id']) && !empty($credentials['store_passwd']),
            'portwallet' => !empty($credentials['app_key']) && !empty($credentials['app_secret']),
            'bkash' => !empty($credentials['app_key']) && !empty($credentials['app_secret'])
                && !empty($credentials['username']) && !empty($credentials['password']),
            'nagad' => !empty($credentials['merchant_id']) && !empty($credentials['private_key']),
            'stripe' => !empty($credentials['secret_key']),
            'paypal' => !empty($credentials['client_id']) && !empty($credentials['client_secret']),
            default => false,
        };

        return $hasCredentials ? ['gateway' => $gateway, 'amount' => (float) ($codDeposit['custom_amount'] ?? 0) ?: $shippingCost] : null;
    }

    private function findByIdempotencyKey(int $companyId, string $key): ?Sell
    {
        return Sell::where('company_id', $companyId)->where('idempotency_key', $key)->first();
    }

    /** The same response the first submit got (payment link only while the payment is still open). */
    private function replay(Sell $sell): array
    {
        $sell->loadMissing('items');
        $subtotal = (float) $sell->items->sum('total_price');
        $discount = (float) $sell->discount;
        $shipping = (float) $sell->shipping_cost;
        $open = $sell->payment_status === 'pending_payment' && $sell->status !== 'Cancelled';
        $depositOnly = $open && (float) $sell->shipping_deposit_amount > 0;

        return [
            'sell' => $sell,
            'payment_url' => $open ? $sell->payment_url : null,
            'deposit_only' => $depositOnly,
            'deposit_amount' => $depositOnly ? (float) $sell->shipping_deposit_amount : null,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping_cost' => $shipping,
            'cod_fee' => max(0, round((float) $sell->amount - max(0, $subtotal - $discount) - $shipping, 2)),
            'gateway_type' => $sell->method,
            'replayed' => true,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e instanceof UniqueConstraintViolationException || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
    /** Start a gateway payment and return its redirect URL (callback URLs per gateway as before). */
    private function initGateway(string $gateway, int $companyId, array $params, string $callbackBase, string $tranId): string
    {
        if ($gateway === 'sslcommerz') {
            $params['success_url'] = "{$callbackBase}/sslcommerz/success";
            $params['fail_url'] = "{$callbackBase}/sslcommerz/fail";
            $params['cancel_url'] = "{$callbackBase}/sslcommerz/cancel";
            $params['ipn_url'] = "{$callbackBase}/sslcommerz/ipn";

            return (new SSLCommerzService($companyId))->initPayment($params);
        }
        if ($gateway === 'portwallet') {
            $params['success_url'] = $params['fail_url'] = $params['cancel_url'] = $params['ipn_url'] = "{$callbackBase}/portwallet/callback";

            return (new PortWalletService($companyId))->initPayment($params);
        }
        if ($gateway === 'stripe') {
            // Stripe uses USD by default; override currency for international shops
            $params['currency'] = 'USD';
            $params['success_url'] = "{$callbackBase}/stripe/success?tran_id={$tranId}";
            $params['cancel_url'] = "{$callbackBase}/stripe/cancel?tran_id={$tranId}";

            return (new StripeService($companyId))->initPayment($params);
        }
        if ($gateway === 'bkash') {
            $params['callback_url'] = "{$callbackBase}/bkash/callback?tran_id={$tranId}";

            return (new BkashService($companyId))->initPayment($params);
        }
        if ($gateway === 'nagad') {
            $params['callback_url'] = "{$callbackBase}/nagad/callback";

            return (new NagadService($companyId))->initPayment($params);
        }
        // paypal
        $params['currency'] = 'USD';
        $params['success_url'] = "{$callbackBase}/paypal/success?tran_id={$tranId}";
        $params['cancel_url'] = "{$callbackBase}/paypal/cancel?tran_id={$tranId}";

        return (new PayPalService($companyId))->initPayment($params);
    }

    /**
     * Undo an order whose payment could not be started: stock back to where it came from, coupon use reverted,
     * rows removed (idempotency key freed so the buyer can retry). Order row locked so this runs once.
     */
    private function discard(Sell $sell): void
    {
        DB::transaction(function () use ($sell) {
            $locked = Sell::whereKey($sell->id)->lockForUpdate()->first();
            if (!$locked) {
                return;
            }
            if ($locked->stock_deducted) {
                $this->reservations->lockProducts((int) $locked->company_id, $locked->items()->pluck('product_id')->all());
                app(SellService::class)->restoreStock($locked);
            }
            foreach (CouponUsage::where('sell_id', $locked->id)->get() as $usage) {
                Coupon::whereKey($usage->coupon_id)->where('times_used', '>', 0)->decrement('times_used');
                $usage->delete();
            }
            $locked->update(['stock_deducted' => false, 'idempotency_key' => null]);
            $locked->items()->delete();
            $locked->delete();
        }, 3);
    }
}
