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
use App\Models\PaymentMethod;
use App\Services\Coupon\CouponService;
use App\Services\Gateway\SSLCommerzService;
use App\Services\Gateway\PortWalletService;
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
        $customer = $request->attributes->get('storefront_customer');

        $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|integer',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.variant_id'       => 'nullable|integer',
            'shipping_address'         => 'required|array',
            'shipping_address.name'    => 'required|string',
            'shipping_address.address' => 'required|string',
            'payment_method'           => 'required|string',
        ]);

        $orderItems = [];
        $subtotal   = 0;

        foreach ($request->items as $item) {
            $product = Product::where('company_id', $customer->company_id)
                ->where('published', true)
                ->find($item['product_id']);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => "Product ID {$item['product_id']} not found",
                ], 422);
            }

            $price     = ($product->sale_price && $product->sale_price > 0) ? $product->sale_price : $product->price;
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
                $couponData    = $this->couponService->validate($customer->company_id, [
                    'code'        => $request->coupon_code,
                    'orderAmount' => $subtotal,
                    'customerId'  => $customer->id,
                ]);
                $discount      = $couponData['discountAmount'] ?? 0;
                $couponCode    = $request->coupon_code;
                // Fetch the Coupon model for usage recording and free_shipping check
                $appliedCoupon = Coupon::where('company_id', $customer->company_id)
                    ->where('code', $request->coupon_code)
                    ->first();
            } catch (HttpException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
            }
        }

        $shippingCost = (float) ($request->shipping_cost ?? 0);
        // Free shipping coupon overrides shipping cost
        if ($appliedCoupon?->free_shipping) {
            $shippingCost = 0;
        }

        $total = max(0, $subtotal - $discount) + $shippingCost;

        $addr = $request->shipping_address;

        $sell = Sell::create([
            'company_id'             => $customer->company_id,
            'customer_id'            => $customer->id,
            'customer_name'          => $customer->name,
            'invoice_no'             => 'ORD-' . strtoupper(uniqid()),
            'order_time'             => now(),
            'amount'                 => $total,
            'method'                 => $request->payment_method,
            'status'                 => 'Pending',
            'payment_status'         => 'pending',
            'fulfillment_status'     => 'unfulfilled',
            'shipping_full_name'     => $addr['name'],
            'shipping_phone'         => $addr['phone'] ?? null,
            'shipping_email'         => $addr['email'] ?? $customer->email,
            'shipping_address_line1' => $addr['address'],
            'shipping_city'          => $addr['city'] ?? null,
            'shipping_state'         => $addr['state'] ?? null,
            'shipping_postal_code'   => $addr['zip'] ?? null,
            'shipping_country'       => $addr['country'] ?? null,
            'coupon_code'            => $couponCode,
            'discount'               => $discount,
            'shipping_cost'          => $shippingCost,
        ]);

        foreach ($orderItems as $item) {
            $sell->items()->create($item);
        }

        // Deduct stock
        foreach ($request->items as $item) {
            $qty = (int) $item['quantity'];
            if (!empty($item['variant_id'])) {
                // Deduct from variant inventory (sum across all locations)
                VariantInventory::where('variant_id', $item['variant_id'])
                    ->orderByDesc('quantity')
                    ->get()
                    ->each(function (VariantInventory $inv) use (&$qty) {
                        if ($qty <= 0) return false;
                        $deduct = min($qty, $inv->quantity);
                        $inv->decrement('quantity', $deduct);
                        $qty -= $deduct;
                    });
                // Also keep variant stock field in sync
                ProductVariant::where('id', $item['variant_id'])
                    ->decrement('stock', (int) $item['quantity']);
            } else {
                // Simple product — decrement product stock
                Product::where('id', $item['product_id'])
                    ->where('company_id', $customer->company_id)
                    ->decrement('stock', $qty);
            }
        }

        // Record coupon usage
        if ($appliedCoupon) {
            CouponUsage::create([
                'coupon_id'        => $appliedCoupon->id,
                'customer_id'      => $customer->id,
                'sell_id'          => $sell->id,
                'coupon_code'      => $appliedCoupon->code,
                'discount_applied' => $discount,
                'original_amount'  => $subtotal,
                'final_amount'     => $total,
                'used_at'          => now(),
            ]);
            $appliedCoupon->increment('usage_count');
        }

        $this->notificationService->notifyOrderPlaced(
            $customer->company_id,
            $sell->invoice_no,
            $customer->name,
            $total
        );

        // Resolve gateway_type from PaymentMethod record
        $paymentMethodRecord = PaymentMethod::where('company_id', $customer->company_id)
            ->whereRaw('LOWER(name) = ?', [strtolower($request->payment_method)])
            ->first();
        $gatewayType = $paymentMethodRecord?->gateway_type ?? 'cod';

        if (in_array($gatewayType, ['sslcommerz', 'portwallet'])) {
            // Mark order as awaiting payment
            $sell->update(['payment_status' => 'pending_payment']);

            $appUrl      = rtrim(config('app.url'), '/');
            $callbackBase = $appUrl . '/api/gateway';
            $tranId      = 'INV-' . $sell->invoice_no;

            $gatewayParams = [
                'amount'           => $total,
                'currency'         => 'BDT',
                'tran_id'          => $tranId,
                'product_name'     => 'Order ' . $sell->invoice_no,
                'customer_name'    => $customer->name,
                'customer_email'   => $customer->email,
                'customer_phone'   => $request->shipping_address['phone'] ?? $customer->phone ?? '',
                'shipping_address' => $request->shipping_address['address'] ?? '',
                'shipping_city'    => $request->shipping_address['city'] ?? 'Dhaka',
            ];

            try {
                if ($gatewayType === 'sslcommerz') {
                    $gatewayParams['success_url'] = "{$callbackBase}/sslcommerz/success";
                    $gatewayParams['fail_url']    = "{$callbackBase}/sslcommerz/fail";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/sslcommerz/cancel";
                    $gatewayParams['ipn_url']     = "{$callbackBase}/sslcommerz/ipn";
                    $service     = new SSLCommerzService($customer->company_id);
                    $paymentUrl  = $service->initPayment($gatewayParams);
                } else {
                    $gatewayParams['success_url'] = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['fail_url']    = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['cancel_url']  = "{$callbackBase}/portwallet/callback";
                    $gatewayParams['ipn_url']     = "{$callbackBase}/portwallet/callback";
                    $service    = new PortWalletService($customer->company_id);
                    $paymentUrl = $service->initPayment($gatewayParams);
                }
            } catch (\Throwable $e) {
                // Delete order so user can retry — don't leave ghost pending_payment orders
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

        return response()->json([
            'success' => true,
            'data'    => $this->formatOrder($sell->load('items')),
        ], 201);
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
