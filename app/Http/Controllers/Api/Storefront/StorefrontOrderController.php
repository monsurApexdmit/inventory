<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sell;
use App\Models\VariantInventory;
use App\Models\Location;
use App\Models\ShippingMethod;
use App\Services\Inventory\LocationStockService;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Coupon\CouponService;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\PortWalletService;
use App\Services\Gateway\StripeService;
use App\Services\Gateway\PayPalService;
use App\Services\Gateway\BkashService;
use App\Services\Gateway\NagadService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StorefrontOrderController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly CouponService $couponService,
    ) {
    }

    // GET /api/store/orders
    public function index(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $orders = Sell::with('items')
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $orders->map(fn($o) => $this->formatOrder($o)),
            'meta'    => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    // GET /api/store/orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $order = Sell::with(['items', 'shipments'])
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->find($id);

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatOrder($order)]);
    }

    // GET /api/store/orders/track?company_id=11&invoice=ORD-XXXX  (public, no auth)
    public function trackOrder(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        $invoice   = trim($request->query('invoice', ''));

        if (!$companyId || !$invoice) {
            return response()->json(['success' => false, 'message' => 'company_id and invoice are required'], 400);
        }

        $order = Sell::with(['items', 'shipments.trackingHistory'])
            ->where('company_id', $companyId)
            ->where('invoice_no', $invoice)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatOrderWithShipment($order)]);
    }

    // POST /api/store/orders
    public function store(Request $request): JsonResponse
    {
        // Logged-in customer (from customer.auth token) if present; null for guests.
        $customer = $request->attributes->get('storefront_customer');

        // Company always comes from the resolve_company middleware (subdomain),
        // so guest orders can still be scoped to the right tenant.
        $companyId = $customer->company_id ?? (int) $request->input('company_id');
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'Store could not be resolved'], 400);
        }

        $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|integer',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.variant_id'       => 'nullable|integer',
            'shipping_address'         => 'required|array',
            'shipping_address.name'    => 'required|string',
            'shipping_address.address' => 'required|string',
            'shipping_address.phone'   => $customer ? 'nullable|string' : 'required|string',
            'payment_method'           => 'required|string',
            'shipping_method_id'       => 'nullable|integer',
            'pickup_location_id'       => 'nullable|integer',
        ]);

        // Buyer identity: real customer when logged in, else the guest details.
        $customerId    = $customer->id ?? null;
        $customerName  = $customer->name ?? ($request->input('shipping_address.name') ?: 'Guest Customer');
        $customerEmail = $customer->email ?? $request->input('shipping_address.email');

        $orderItems = [];
        $subtotal   = 0;

        foreach ($request->items as $item) {
            $product = Product::where('company_id', $companyId)
                ->where('published', true)
                ->find($item['product_id']);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => "Product ID {$item['product_id']} not found",
                ], 422);
            }

            // Normal price, the live offer price (expired offers price at normal) or the wholesale price from the minimum quantity
            $variantModel = !empty($item['variant_id'])
                ? ProductVariant::where('product_id', $product->id)->find($item['variant_id'])
                : null;
            $price     = \App\Services\Pricing\PriceService::resolve($product, $variantModel, (float) $item['quantity'])['unit'];
            $lineTotal = $price * $item['quantity'];
            $subtotal += $lineTotal;

            $orderItems[] = [
                'product_id'   => $product->id,
                'variant_id'   => $item['variant_id'] ?? null,
                'product_name' => $product->name,
                'quantity'     => $item['quantity'],
                'unit_price'   => $price,
                'total_price'  => $lineTotal,
                'unit_cost'    => $product->cost_price ?? 0,
                'total_cost'   => ($product->cost_price ?? 0) * $item['quantity'],
            ];
        }

        // Validate and apply coupon
        $discount      = 0;
        $couponCode    = null;
        $appliedCoupon = null;

        if ($request->filled('coupon_code')) {
            try {
                $couponData    = $this->couponService->validate($companyId, [
                    'code'        => $request->coupon_code,
                    'orderAmount' => $subtotal,
                    'customerId'  => $customerId,
                    'customerEmail' => $customerEmail,
                    'channel'     => 'online',
                    'items'       => array_map(fn ($oi) => [
                        'productId'  => $oi['product_id'],
                        'totalPrice' => $oi['total_price'],
                    ], $orderItems),
                ]);
                $discount      = $couponData['discountAmount'] ?? 0;
                $couponCode    = $request->coupon_code;
                // Fetch the Coupon model for usage recording and free_shipping check
                $appliedCoupon = Coupon::where('company_id', $companyId)
                    ->where('code', $request->coupon_code)
                    ->first();
            } catch (HttpException $e) {
                $decoded = json_decode($e->getMessage(), true);
                return response()->json(['success' => false, 'message' => is_array($decoded) ? ($decoded['message'] ?? 'Coupon is not valid') : $e->getMessage()], $e->getStatusCode());
            }
        }

        // Shipping is priced SERVER-SIDE from the chosen method (a client-sent shipping_cost is ignored).
        // Pickup orders are free; delivery orders must pick an active delivery option of this store.
        $shippingMethodModel = $request->filled('shipping_method_id')
            ? ShippingMethod::where('company_id', $companyId)->find($request->shipping_method_id) : null;
        $isPickup = ($shippingMethodModel?->type === 'pickup') || $request->filled('pickup_location_id');
        $shippingCost = 0.0;
        if ($isPickup) {
            if ($shippingMethodModel && $shippingMethodModel->type !== 'pickup') {
                $shippingMethodModel = null;
            }
        } else {
            $shippingMethodModel = app(\App\Services\Shipping\ShippingMethodService::class)
                ->findActiveDelivery($companyId, $request->input('shipping_method_id'));
            if (!$shippingMethodModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose a delivery option',
                    'errors'  => ['shipping_method_id' => ['Choose a delivery option']],
                ], 422);
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
        $selectedMethod = PaymentMethod::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower((string) $request->payment_method)])
            ->first();
        $chosen = strtolower(trim((string) $request->payment_method));
        $manualSlug = null;
        $savedMethods = is_array($paySettings['methods'] ?? null) ? $paySettings['methods'] : [];
        if ($savedMethods) {
            $labels = \App\Http\Requests\Setting\UpdatePaymentSettingsRequest::METHODS;
            foreach (self::onlineMethodSlugs($paySettings) as $slug) {
                if ($chosen === $slug || $chosen === strtolower($labels[$slug][0] ?? '')) {
                    $manualSlug = $slug;
                    break;
                }
            }
            $legacyGateway = $selectedMethod && $selectedMethod->is_active && ($selectedMethod->gateway_type ?? 'cod') !== 'cod';
            if (!$manualSlug && !$legacyGateway) {
                return response()->json(['success' => false, 'message' => 'This payment method is not available for online orders.'], 422);
            }
        }
        // 'manual' = enabled non-COD method with no gateway record (bKash/Nagad/card/bank/cash): no COD rules
        $gatewayType = $selectedMethod?->gateway_type ?? ($manualSlug && $manualSlug !== 'cod' ? 'manual' : 'cod');

        // Pickup orders collect from a chosen pickup point (an is_pickup_point location) and take stock from it
        $locStock = app(LocationStockService::class);
        $pickupLocationId = null;
        if ($isPickup) {
            if (!$request->filled('pickup_location_id')) {
                return response()->json(['success' => false, 'message' => 'Choose a pickup point for pickup orders.'], 422);
            }
            $pickupQ = Location::where('company_id', $companyId)->whereKey($request->pickup_location_id)->pickupPoints();
            $pickupLocationId = $pickupQ->value('id');
            if (!$pickupLocationId) {
                return response()->json(['success' => false, 'message' => 'The selected pickup point is not available.'], 422);
            }
            $pickupLocationId = (int) $pickupLocationId;
        }

        // Stock check against the locations this order draws from
        $onlineLocs = $locStock->storefrontLocationIds($companyId);
        $drawLocs = $pickupLocationId ? [$pickupLocationId] : $onlineLocs;
        if ($drawLocs && $locStock->hasLocationStock($companyId)) {
            $need = [];
            foreach ($request->items as $item) {
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
                    return response()->json(['success' => false, 'message' => "Only {$have} {$p->name} in stock{$where}"], 422);
                }
            }
        }

        // Cash-on-delivery rules (Payments settings): order limit (default 5000) + extra COD fee.
        if ($gatewayType === 'cod') {
            $codCfg   = $paySettings;
            $codLimit = (float) ($codCfg['cod_limit'] ?? 5000);
            if ($codLimit > 0 && max(0, $subtotal - $discount) >= $codLimit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash on delivery is only available for orders under ' . rtrim(rtrim(number_format($codLimit, 2, '.', ''), '0'), '.') . '. Please choose another payment method.',
                ], 422);
            }
            $codFee = max(0, (float) ($codCfg['cod_fee'] ?? 0));
        }

        $total = max(0, $subtotal - $discount) + $shippingCost + $codFee;

        $addr = $request->shipping_address;

        $sell = Sell::create([
            'company_id'             => $companyId,
            'customer_id'            => $customerId,
            'customer_name'          => $customerName,
            'invoice_no'             => 'ORD-' . strtoupper(uniqid()),
            'order_time'             => now(),
            'amount'                 => $total,
            'method'                 => $request->payment_method,
            'source'                 => 'storefront',
            'status'                 => 'Pending',
            'payment_status'         => 'pending',
            'fulfillment_status'     => 'unfulfilled',
            'shipping_full_name'     => $addr['name'],
            'shipping_phone'         => $addr['phone'] ?? null,
            'shipping_email'         => $addr['email'] ?? $customerEmail,
            'shipping_address_line1' => $addr['address'],
            'shipping_city'          => $addr['city'] ?? null,
            'shipping_state'         => $addr['state'] ?? null,
            'shipping_postal_code'   => $addr['zip'] ?? null,
            'shipping_country'       => $addr['country'] ?? null,
            'coupon_code'            => $couponCode,
            'discount'               => $discount,
            'shipping_cost'          => $shippingCost,
            'shipping_method'        => $shippingMethodModel?->id,
            'location_id'            => $pickupLocationId,
        ]);

        foreach ($orderItems as $item) {
            $sell->items()->create($item);
        }

        // Deduct stock: per location when location stock is set up (pickup point, else ships-online
        // locations biggest first), otherwise the flat/legacy way
        if ($drawLocs && $locStock->hasLocationStock($companyId)) {
            $byLoc = [];
            $meta = ['reference_type' => 'sell', 'reference_id' => $sell->id, 'reason' => 'Online order', 'notes' => $sell->invoice_no];
            foreach ($request->items as $item) {
                $product = Product::where('company_id', $companyId)->find($item['product_id']);
                $variantId = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
                try {
                    $taken = $pickupLocationId
                        ? $locStock->deductGreedy($companyId, $product, $variantId, [$pickupLocationId], (int) $item['quantity'], $meta)
                        : $locStock->deductGreedy($companyId, $product, $variantId, $drawLocs, (int) $item['quantity'], $meta);
                } catch (HttpException $e) {
                    $locStock->reverseSale($companyId, $sell->id);
                    $sell->items()->delete();
                    $sell->delete();
                    return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
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
            foreach ($request->items as $item) {
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
                'coupon_id'        => $appliedCoupon->id,
                'customer_id'      => $customerId,
                'sell_id'          => $sell->id,
                'coupon_code'      => $appliedCoupon->code,
                'discount_applied' => $discount,
                'original_amount'  => $subtotal,
                'final_amount'     => $total,
                'used_at'          => now(),
            ]);
            $appliedCoupon->increment('times_used');
        }

        $this->notificationService->notifyOrderPlaced(
            $companyId,
            $sell->invoice_no,
            $customerName,
            $total
        );


        if (in_array($gatewayType, ['sslcommerz', 'portwallet', 'stripe', 'paypal', 'bkash', 'nagad'])) {
            $sell->update(['payment_status' => 'pending_payment']);

            $appUrl       = rtrim(config('app.url'), '/');
            $callbackBase = $appUrl . '/api/gateway';
            $tranId       = 'INV-' . $sell->invoice_no;

            $gatewayParams = [
                'amount'           => $total,
                'currency'         => 'BDT',
                'tran_id'          => $tranId,
                'product_name'     => 'Order ' . $sell->invoice_no,
                'customer_name'    => $customerName,
                'customer_email'   => $customerEmail,
                'customer_phone'   => $request->shipping_address['phone'] ?? ($request->input("shipping_address.phone")) ?? '',
                'shipping_address' => $request->shipping_address['address'] ?? '',
                'shipping_city'    => $request->shipping_address['city'] ?? 'Dhaka',
                'brand_name'       => config('app.name', 'Shop'),
            ];

            try {
                if ($gatewayType === 'sslcommerz') {
                    $gatewayParams['success_url'] = "{$callbackBase}/sslcommerz/success";
                    $gatewayParams['fail_url']    = "{$callbackBase}/sslcommerz/fail";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/sslcommerz/cancel";
                    $gatewayParams['ipn_url']     = "{$callbackBase}/sslcommerz/ipn";
                    $service    = new SSLCommerzService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);

                } elseif ($gatewayType === 'portwallet') {
                    $gatewayParams['success_url'] = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['fail_url']    = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['ipn_url']     = "{$callbackBase}/portwallet/callback";
                    $service    = new PortWalletService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);

                } elseif ($gatewayType === 'stripe') {
                    // Stripe uses USD by default; override currency for international shops
                    $gatewayParams['currency']    = 'USD';
                    $gatewayParams['success_url'] = "{$callbackBase}/stripe/success?tran_id={$tranId}";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/stripe/cancel?tran_id={$tranId}";
                    $service    = new StripeService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);

                } elseif ($gatewayType === 'bkash') {
                    $gatewayParams['callback_url'] = "{$callbackBase}/bkash/callback?tran_id={$tranId}";
                    $service    = new BkashService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);

                } elseif ($gatewayType === 'nagad') {
                    $gatewayParams['callback_url'] = "{$callbackBase}/nagad/callback";
                    $service    = new NagadService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);

                } else { // paypal
                    $gatewayParams['currency']    = 'USD';
                    $gatewayParams['success_url'] = "{$callbackBase}/paypal/success?tran_id={$tranId}";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/paypal/cancel?tran_id={$tranId}";
                    $service    = new PayPalService($companyId);
                    $paymentUrl = $service->initPayment($gatewayParams);
                }
            } catch (\Throwable $e) {
                $locStock->reverseSale($companyId, $sell->id);
                $sell->items()->delete();
                $sell->delete();
                return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
            }

            return response()->json([
                'success'     => true,
                'data'        => $this->formatOrder($sell->load('items')),
                'payment_url' => $paymentUrl,
            ], 201);
        }

        // COD shipping deposit — charge only shipping cost via gateway before delivery
        if ($gatewayType === 'cod' && $shippingCost > 0) {
            $setting     = Setting::where('company_id', $companyId)->first();
            $codDeposit  = $setting?->payment_settings['cod_shipping_deposit'] ?? [];
            $depositEnabled  = (bool) ($codDeposit['enabled'] ?? false);
            $depositGateway  = $codDeposit['gateway'] ?? 'sslcommerz';
            $depositAmount   = (float) ($codDeposit['custom_amount'] ?? 0) ?: $shippingCost;

            // Guard: check that the selected deposit gateway has credentials before redirecting
            $allPaymentSettings = $setting?->payment_settings ?? [];
            $gatewayCredentials = $allPaymentSettings[$depositGateway] ?? [];
            $hasCredentials = match($depositGateway) {
                'sslcommerz' => !empty($gatewayCredentials['store_id']) && !empty($gatewayCredentials['store_passwd']),
                'portwallet'  => !empty($gatewayCredentials['app_key']) && !empty($gatewayCredentials['app_secret']),
                'bkash'       => !empty($gatewayCredentials['app_key']) && !empty($gatewayCredentials['app_secret'])
                                 && !empty($gatewayCredentials['username']) && !empty($gatewayCredentials['password']),
                'nagad'       => !empty($gatewayCredentials['merchant_id']) && !empty($gatewayCredentials['private_key']),
                'stripe'      => !empty($gatewayCredentials['secret_key']),
                'paypal'      => !empty($gatewayCredentials['client_id']) && !empty($gatewayCredentials['client_secret']),
                default       => false,
            };

            if ($depositEnabled && $hasCredentials) {
                $sell->update([
                    'payment_status'         => 'pending_payment',
                    'shipping_deposit_amount' => $depositAmount,
                ]);

                $appUrl       = rtrim(config('app.url'), '/');
                $callbackBase = $appUrl . '/api/gateway/cod-deposit';
                $tranId       = 'INV-' . $sell->invoice_no;

                $depositParams = [
                    'amount'           => $depositAmount,
                    'currency'         => 'BDT',
                    'tran_id'          => $tranId,
                    'product_name'     => 'Shipping Deposit – Order ' . $sell->invoice_no,
                    'customer_name'    => $customerName,
                    'customer_email'   => $customerEmail,
                    'customer_phone'   => $request->shipping_address['phone'] ?? ($request->input("shipping_address.phone")) ?? '',
                    'shipping_address' => $request->shipping_address['address'] ?? '',
                    'shipping_city'    => $request->shipping_address['city'] ?? 'Dhaka',
                    'brand_name'       => config('app.name', 'Shop'),
                ];

                try {
                    if ($depositGateway === 'sslcommerz') {
                        $depositParams['success_url'] = "{$callbackBase}/sslcommerz/success";
                        $depositParams['fail_url']    = "{$callbackBase}/sslcommerz/fail";
                        $depositParams['cancel_url']  = "{$callbackBase}/sslcommerz/cancel";
                        $depositParams['ipn_url']     = "{$callbackBase}/sslcommerz/ipn";
                        $service    = new SSLCommerzService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    } elseif ($depositGateway === 'portwallet') {
                        $depositParams['success_url'] = "{$callbackBase}/portwallet/callback";
                        $depositParams['fail_url']    = "{$callbackBase}/portwallet/callback";
                        $depositParams['cancel_url']  = "{$callbackBase}/portwallet/callback";
                        $depositParams['ipn_url']     = "{$callbackBase}/portwallet/callback";
                        $service    = new PortWalletService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    } elseif ($depositGateway === 'bkash') {
                        $tranId = 'INV-' . $sell->invoice_no;
                        $depositParams['callback_url'] = "{$callbackBase}/bkash/callback?tran_id={$tranId}";
                        $service    = new BkashService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    } elseif ($depositGateway === 'nagad') {
                        $depositParams['callback_url'] = "{$callbackBase}/nagad/callback";
                        $service    = new NagadService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    } elseif ($depositGateway === 'stripe') {
                        $depositParams['currency']    = 'USD';
                        $depositParams['success_url'] = "{$callbackBase}/stripe/success?tran_id={$tranId}";
                        $depositParams['cancel_url']  = "{$callbackBase}/stripe/cancel?tran_id={$tranId}";
                        $service    = new StripeService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    } else { // paypal
                        $depositParams['currency']    = 'USD';
                        $depositParams['success_url'] = "{$callbackBase}/paypal/success?tran_id={$tranId}";
                        $depositParams['cancel_url']  = "{$callbackBase}/paypal/cancel?tran_id={$tranId}";
                        $service    = new PayPalService($companyId);
                        $paymentUrl = $service->initPayment($depositParams);
                    }
                } catch (\Throwable $e) {
                    $locStock->reverseSale($companyId, $sell->id);
                    $sell->items()->delete();
                    $sell->delete();
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
                }

                return response()->json([
                    'success'      => true,
                    'data'         => $this->formatOrder($sell->load('items')),
                    'payment_url'  => $paymentUrl,
                    'deposit_only' => true,
                    'deposit_amount' => $depositAmount,
                ], 201);
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrder($sell->load('items')),
        ], 201);
    }

    /**
     * Slugs of the payment methods enabled for the online channel (settings payments.methods).
     * A method may carry `channels`; when set it must include "online".
     */
    public static function onlineMethodSlugs(array $paySettings): array
    {
        $out = [];
        foreach (($paySettings['methods'] ?? []) as $slug => $m) {
            if (!is_array($m) || !filter_var($m['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            if (isset($m['channels']) && is_array($m['channels']) && !in_array('online', $m['channels'], true)) {
                continue;
            }
            $out[] = (string) $slug;
        }
        return $out;
    }

    private function formatOrder(Sell $order): array
    {
        return [
            'id'                 => $order->id,
            'invoice_no'         => $order->invoice_no,
            'order_time'         => $order->order_time,
            'amount'             => $order->amount,
            'shipping_cost'      => $order->shipping_cost,
            'discount'           => $order->discount,
            'status'             => $order->status,
            'payment_status'     => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'tracking_number'    => $order->tracking_number,
            'carrier'            => $order->carrier,
            'method'             => $order->method,
            'shipping_address'   => [
                'name'     => $order->shipping_full_name,
                'phone'    => $order->shipping_phone,
                'address'  => $order->shipping_address_line1,
                'address2' => $order->shipping_address_line2,
                'city'     => $order->shipping_city,
                'state'    => $order->shipping_state,
                'zip'      => $order->shipping_postal_code,
                'country'  => $order->shipping_country,
            ],
            'payment_transaction_id'          => $order->payment_transaction_id,
            'shipping_deposit_amount'          => $order->shipping_deposit_amount !== null ? (float) $order->shipping_deposit_amount : null,
            'shipping_deposit_transaction_id'  => $order->shipping_deposit_transaction_id,
            'items' => $order->items->map(fn($item) => [
                'id'           => $item->id,
                'product_id'   => $item->product_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
                'total_price'  => $item->total_price,
            ])->values(),
        ];
    }

    private function formatOrderWithShipment(Sell $order): array
    {
        $base     = $this->formatOrder($order);
        $shipment = $order->shipments?->sortByDesc('created_at')->first();

        $base['shipment'] = $shipment ? [
            'tracking_number'    => $shipment->tracking_number,
            'carrier'            => $shipment->carrier,
            'shipping_method'    => $shipment->shipping_method,
            'status'             => $shipment->status,
            'shipped_at'         => $shipment->shipped_at,
            'estimated_delivery' => $shipment->estimated_delivery,
            'delivered_at'       => $shipment->delivered_at,
            'tracking_history'   => $shipment->trackingHistory
                ->sortByDesc('event_time')
                ->map(fn($h) => [
                    'status'      => $h->status,
                    'location'    => $h->location,
                    'description' => $h->description,
                    'event_time'  => $h->event_time,
                ])->values(),
        ] : null;

        return $base;
    }
}
