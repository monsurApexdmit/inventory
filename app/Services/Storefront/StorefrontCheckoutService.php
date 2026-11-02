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
use App\Models\VariantInventory;
use App\Services\Coupon\CouponService;
use App\Services\Gateway\BkashService;
use App\Services\Gateway\NagadService;
use App\Services\Gateway\PayPalService;
use App\Services\Gateway\PortWalletService;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\StripeService;
use App\Services\Inventory\LocationStockService;
use App\Services\Notification\NotificationService;
use App\Services\Pricing\PriceService;
use App\Services\Shipping\ShippingMethodService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Places a storefront order. Shared by the normal checkout (POST /store/orders) and the
 * quick order form (POST /store/quick-order) so pricing (PriceService), coupons, server-side
 * delivery charge, payment-method rules, COD limit/fee, location stock and gateways stay identical.
 */
class StorefrontCheckoutService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @param array $input  Validated checkout input (snake_case, as POST /store/orders):
     *                      items[{product_id, variant_id?, quantity}], shipping_address{name, phone?, email?, address, address2?,
     *                      city?, state?, zip?, country?}, payment_method, shipping_method_id?, pickup_location_id?, coupon_code?,
     *                      transaction_id?, note?
     * @param array $ctx    customer_id?, customer_name, customer_email?, attribution? (sells columns from AttributionService),
     *                      is_quick_order?, possible_duplicate?, require_variant? (bool), campaign_link_name?
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

        // Stock check against the locations this order draws from
        $onlineLocs = $locStock->storefrontLocationIds($companyId);
        $drawLocs = $pickupLocationId ? [$pickupLocationId] : $onlineLocs;
        if ($drawLocs && $locStock->hasLocationStock($companyId)) {
            $need = [];
            foreach ($items as $item) {
                $k = $item['product_id'] . '|' . ($item['variant_id'] ?? '');
                $need[$k] = [$item['product_id'], $item['variant_id'] ?? null, ($need[$k][2] ?? 0) + (int) $item['quantity']];
            }
            foreach ($need as [$pid, $vid, $qty]) {
                $p = Product::where('company_id', $companyId)->find($pid);
                if (!$p) {
                    continue;
                }
                $have = $locStock->availableAcross($p, $vid ? (int) $vid : null, $drawLocs);
                if ($have < $qty) {
                    $where = $pickupLocationId ? ' at ' . Location::whereKey($pickupLocationId)->value('name') : '';
                    throw new StorefrontOrderException("Only {$have} {$p->name} in stock{$where}");
                }
            }
        }

        // Cash-on-delivery rules (Payments settings): order limit (default 5000) + extra COD fee.
        if ($gatewayType === 'cod') {
            $codLimit = (float) ($paySettings['cod_limit'] ?? 5000);
            if ($codLimit > 0 && max(0, $subtotal - $discount) >= $codLimit) {
                throw new StorefrontOrderException('Cash on delivery is only available for orders under ' . rtrim(rtrim(number_format($codLimit, 2, '.', ''), '0'), '.') . '. Please choose another payment method.');
            }
            $codFee = max(0, (float) ($paySettings['cod_fee'] ?? 0));
        }

        $total = max(0, $subtotal - $discount) + $shippingCost + $codFee;

        $addr = $input['shipping_address'];
        $attribution = is_array($ctx['attribution'] ?? null) ? $ctx['attribution'] : [];
        $txn = isset($input['transaction_id']) ? trim((string) $input['transaction_id']) : '';
        $note = isset($input['note']) ? trim((string) $input['note']) : '';

        $sell = Sell::create(array_merge($attribution, [
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'invoice_no' => 'ORD-' . strtoupper(uniqid()),
            'order_time' => now(),
            'amount' => $total,
            'method' => $paymentMethod,
            'source' => 'storefront',
            'status' => 'Pending',
            'payment_status' => 'pending',
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
        ]));

        foreach ($orderItems as $item) {
            $sell->items()->create($item);
        }

        // Deduct stock: per location when location stock is set up (pickup point, else ships-online
        // locations biggest first), otherwise the flat/legacy way
        if ($drawLocs && $locStock->hasLocationStock($companyId)) {
            $byLoc = [];
            $meta = ['reference_type' => 'sell', 'reference_id' => $sell->id, 'reason' => 'Online order', 'notes' => $sell->invoice_no];
            foreach ($items as $item) {
                $product = Product::where('company_id', $companyId)->find($item['product_id']);
                $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                try {
                    $taken = $locStock->deductGreedy($companyId, $product, $variantId, $pickupLocationId ? [$pickupLocationId] : $drawLocs, (int) $item['quantity'], $meta);
                } catch (HttpException $e) {
                    $this->discard($sell, $companyId, $locStock);
                    throw new StorefrontOrderException($e->getMessage(), $e->getStatusCode());
                }
                foreach ($taken as $loc => $q) {
                    $byLoc[$loc] = ($byLoc[$loc] ?? 0) + $q;
                }
            }
            if (!$pickupLocationId && $byLoc) {
                arsort($byLoc);
                $sell->update(['location_id' => array_key_first($byLoc)]);
            }
        } else {
            foreach ($items as $item) {
                $qty = (int) $item['quantity'];
                if (!empty($item['variant_id'])) {
                    VariantInventory::where('variant_id', $item['variant_id'])
                        ->orderByDesc('quantity')
                        ->get()
                        ->each(function (VariantInventory $inv) use (&$qty) {
                            if ($qty <= 0) return false;
                            $deduct = min($qty, $inv->quantity);
                            $inv->decrement('quantity', $deduct);
                            $qty -= $deduct;
                        });
                    ProductVariant::where('id', $item['variant_id'])
                        ->decrement('stock', (int) $item['quantity']);
                } else {
                    Product::where('id', $item['product_id'])
                        ->where('company_id', $companyId)
                        ->decrement('stock', $qty);
                }
            }
        }
        $sell->update(['stock_deducted' => true]);

        // Record coupon usage
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

        $gatewayCustomer = [
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $addr['phone'] ?? '',
            'shipping_address' => $addr['address'] ?? '',
            'shipping_city' => $addr['city'] ?? 'Dhaka',
            'brand_name' => config('app.name', 'Shop'),
        ];

        if (in_array($gatewayType, ['sslcommerz', 'portwallet', 'stripe', 'paypal', 'bkash', 'nagad'])) {
            $sell->update(['payment_status' => 'pending_payment']);

            $callbackBase = rtrim(config('app.url'), '/') . '/api/gateway';
            $tranId = 'INV-' . $sell->invoice_no;

            $gatewayParams = [
                'amount' => $total,
                'currency' => 'BDT',
                'tran_id' => $tranId,
                'product_name' => 'Order ' . $sell->invoice_no,
            ] + $gatewayCustomer;

            try {
                $result['payment_url'] = $this->initGateway($gatewayType, $companyId, $gatewayParams, $callbackBase, $tranId);
            } catch (\Throwable $e) {
                $this->discard($sell, $companyId, $locStock);
                throw new StorefrontOrderException($e->getMessage(), 502);
            }

            return $result;
        }

        // COD shipping deposit — charge only shipping cost via gateway before delivery
        if ($gatewayType === 'cod' && $shippingCost > 0) {
            $setting = Setting::where('company_id', $companyId)->first();
            $codDeposit = $setting?->payment_settings['cod_shipping_deposit'] ?? [];
            $depositEnabled = (bool) ($codDeposit['enabled'] ?? false);
            $depositGateway = $codDeposit['gateway'] ?? 'sslcommerz';
            $depositAmount = (float) ($codDeposit['custom_amount'] ?? 0) ?: $shippingCost;

            // Guard: check that the selected deposit gateway has credentials before redirecting
            $gatewayCredentials = ($setting?->payment_settings ?? [])[$depositGateway] ?? [];
            $hasCredentials = match ($depositGateway) {
                'sslcommerz' => !empty($gatewayCredentials['store_id']) && !empty($gatewayCredentials['store_passwd']),
                'portwallet' => !empty($gatewayCredentials['app_key']) && !empty($gatewayCredentials['app_secret']),
                'bkash' => !empty($gatewayCredentials['app_key']) && !empty($gatewayCredentials['app_secret'])
                    && !empty($gatewayCredentials['username']) && !empty($gatewayCredentials['password']),
                'nagad' => !empty($gatewayCredentials['merchant_id']) && !empty($gatewayCredentials['private_key']),
                'stripe' => !empty($gatewayCredentials['secret_key']),
                'paypal' => !empty($gatewayCredentials['client_id']) && !empty($gatewayCredentials['client_secret']),
                default => false,
            };

            if ($depositEnabled && $hasCredentials) {
                $sell->update([
                    'payment_status' => 'pending_payment',
                    'shipping_deposit_amount' => $depositAmount,
                ]);

                $callbackBase = rtrim(config('app.url'), '/') . '/api/gateway/cod-deposit';
                $tranId = 'INV-' . $sell->invoice_no;

                $depositParams = [
                    'amount' => $depositAmount,
                    'currency' => 'BDT',
                    'tran_id' => $tranId,
                    'product_name' => 'Shipping Deposit – Order ' . $sell->invoice_no,
                ] + $gatewayCustomer;

                try {
                    $result['payment_url'] = $this->initGateway($depositGateway, $companyId, $depositParams, $callbackBase, $tranId);
                } catch (\Throwable $e) {
                    $this->discard($sell, $companyId, $locStock);
                    throw new StorefrontOrderException($e->getMessage(), 502);
                }
                $result['deposit_only'] = true;
                $result['deposit_amount'] = $depositAmount;
            }
        }

        return $result;
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

    /** Undo a sell that could not be completed (stock back to its locations, rows removed). */
    private function discard(Sell $sell, int $companyId, LocationStockService $locStock): void
    {
        $locStock->reverseSale($companyId, $sell->id);
        $sell->items()->delete();
        $sell->delete();
    }
}
