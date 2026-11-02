<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Sell;
use App\Services\Marketing\AttributionService;
use App\Services\Storefront\StorefrontCheckoutService;
use App\Services\Storefront\StorefrontOrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontOrderController extends Controller
{
    public function __construct(
        private readonly StorefrontCheckoutService $checkout,
        private readonly AttributionService $attribution,
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
            'transaction_id'           => 'nullable|string|max:100',
            'attribution'              => 'nullable|array',
        ]);

        // Order attribution (utm / click id / campaign link / referrer) — same rules as quick orders
        $attribution = $request->has('attribution')
            ? $this->attribution->fromInput((int) $companyId, (array) $request->input('attribution'), $request->getHost())
            : [];

        try {
            $placed = $this->checkout->place((int) $companyId, [
                'items'              => $request->input('items'),
                'shipping_address'   => $request->input('shipping_address'),
                'payment_method'     => (string) $request->input('payment_method'),
                'shipping_method_id' => $request->input('shipping_method_id'),
                'pickup_location_id' => $request->input('pickup_location_id'),
                'coupon_code'        => $request->filled('coupon_code') ? $request->input('coupon_code') : null,
                'transaction_id'     => $request->input('transaction_id'),
            ], [
                // Buyer identity: real customer when logged in, else the guest details.
                'customer_id'        => $customer->id ?? null,
                'customer_name'      => $customer->name ?? ($request->input('shipping_address.name') ?: 'Guest Customer'),
                'customer_email'     => $customer->email ?? $request->input('shipping_address.email'),
                'attribution'        => $attribution,
                'campaign_link_name' => !empty($attribution['campaign_link_id'])
                    ? \App\Models\CampaignLink::withTrashed()->whereKey($attribution['campaign_link_id'])->value('name') : null,
            ]);
        } catch (StorefrontOrderException $e) {
            return $e->toResponse();
        }

        $sell = $placed['sell'];
        if ($placed['deposit_only']) {
            return response()->json([
                'success'        => true,
                'data'           => $this->formatOrder($sell->load('items')),
                'payment_url'    => $placed['payment_url'],
                'deposit_only'   => true,
                'deposit_amount' => $placed['deposit_amount'],
            ], 201);
        }
        if ($placed['payment_url']) {
            return response()->json([
                'success'     => true,
                'data'        => $this->formatOrder($sell->load('items')),
                'payment_url' => $placed['payment_url'],
            ], 201);
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
