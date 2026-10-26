<?php

namespace App\Services\Sell;

use App\DTOs\Sell\SellDTO;
use App\DTOs\Sell\SellMapper;
use App\Models\Coupon;
use App\Models\ProductBatch;
use App\Models\ProductBundleItem;
use App\Models\ProductSerial;
use App\Models\CouponUsage;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sell;
use App\Models\ShippingAddress;
use App\Models\VariantInventory;
use App\Repositories\Contracts\ISellRepository;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SellService
{
    private readonly SellMapper $mapper;

    public function __construct(
        private readonly ISellRepository $repository,
        private readonly NotificationService $notificationService,
    ) {
        $this->mapper = new SellMapper();
    }

    /**
     * List sells for a company
     */
    public function list(int $companyId, array $filters): array
    {
        $result = $this->repository->findByCompany($companyId, $filters);

        // Handle limit vs pagination
        if (!empty($filters['limit'])) {
            // Result is a collection for limit
            // Don't call toArray() on collection - iterate instead to keep models
            $data = [];
            foreach ($result as $sell) {
                $data[] = $this->mapper->toDTO($sell)->toArray();
            }
            return ['data' => $data];
        }

        // Pagination response
        $items = $result->items();
        $data = [];
        foreach ($items as $sell) {
            $data[] = $this->mapper->toDTO($sell)->toArray();
        }
        return [
            'data' => $data,
            'total' => $result->total(),
            'per_page' => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page' => $result->lastPage(),
        ];
    }

    /**
     * Count and revenue total of all sells matching the list filters
     */
    public function summarize(int $companyId, array $filters): array
    {
        return $this->repository->summarize($companyId, $filters);
    }

    public function tabCounts(int $companyId, array $filters): array
    {
        return $this->repository->tabCounts($companyId, $filters);
    }

    public function kpis(int $companyId): array
    {
        return $this->repository->kpis($companyId);
    }

    /**
     * Update delivery details (receiver, phone, address, note) of an open order
     */
    public function updateDelivery(int $id, int $companyId, array $data): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }
        if ($sell->status === 'Cancelled') {
            throw new HttpException(422, 'A cancelled order cannot be changed');
        }
        if ($sell->status === 'Delivered') {
            throw new HttpException(422, 'A delivered order cannot be changed');
        }

        $update = [
            'shipping_full_name' => trim($data['shippingFullName']),
            'shipping_phone' => trim($data['shippingPhone']),
            'shipping_address_line1' => trim($data['shippingAddressLine1']),
        ];
        if (array_key_exists('note', $data)) {
            $update['notes'] = $data['note'] !== null ? trim($data['note']) : null;
        }
        foreach (['deliveryNote' => 'delivery_note', 'zoneName' => 'zone_name', 'courier' => 'carrier', 'transactionId' => 'transaction_id'] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $v = $data[$in] !== null ? trim((string) $data[$in]) : null;
                $update[$col] = $v === '' ? null : $v;
            }
        }
        $this->repository->update($id, $update);

        return $this->mapper->toDTO($this->repository->findByIdAndCompany($id, $companyId));
    }

    /**
     * Get a single sell by ID
     */
    public function get(int $id, int $companyId): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }
        return $this->mapper->toDTO($sell);
    }

    /**
     * Get a sell by invoice number
     */
    public function getByInvoice(string $invoiceNo, int $companyId): SellDTO
    {
        $sell = $this->repository->findByInvoiceAndCompany($invoiceNo, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }
        return $this->mapper->toDTO($sell);
    }

    /**
     * Create a new sell with transactional stock deduction
     */
    public function create(int $companyId, array $data): SellDTO
    {
        return DB::transaction(function () use ($companyId, $data) {
            // Validate customer name
            if (empty($data['customerName'])) {
                throw new HttpException(400, 'Customer name is required');
            }

            // Validate status
            if (!empty($data['status']) && !in_array($data['status'], ['Pending', 'Processing', 'Packed', 'Shipped', 'Delivered'])) {
                throw new HttpException(400, 'Invalid status. Must be one of: Pending, Processing, Packed, Shipped, Delivered');
            }

            // Packed / Shipped are fulfillment stages of a Processing order, not order statuses
            $initialFulfillment = null;
            if (($data['status'] ?? null) === 'Packed' || ($data['status'] ?? null) === 'Shipped') {
                $initialFulfillment = strtolower($data['status']);
                $data['status'] = 'Processing';
            }

            // Cash on delivery needs a customer to collect from, and is never paid up front
            if (($data['method'] ?? null) === 'COD') {
                if (empty($data['customerId'])) {
                    throw new HttpException(422, 'Cash on delivery needs a customer.');
                }
                if ((float) ($data['paidAmount'] ?? 0) > 0) {
                    throw new HttpException(422, 'Cash on delivery orders are paid on delivery, not at checkout.');
                }
            }
            $courier = isset($data['courier']) ? trim((string) $data['courier']) : '';
            unset($data['courier']);

            // Payment reference: transactionId and paymentTransactionId are aliases
            $txnRef = $data['transactionId'] ?? $data['paymentTransactionId'] ?? null;
            if ($txnRef !== null && trim((string) $txnRef) !== '') {
                $data['transactionId'] = $data['paymentTransactionId'] = trim((string) $txnRef);
            } else {
                unset($data['transactionId'], $data['paymentTransactionId']);
            }
            // Tax is stored as sent (the amount already includes it)
            foreach (['taxRate' => [0, 100], 'taxAmount' => [0, null]] as $k => [$lo, $hi]) {
                if (isset($data[$k]) && $data[$k] !== '') {
                    $v = (float) $data[$k];
                    if ($v < $lo || ($hi !== null && $v > $hi)) {
                        throw new HttpException(422, $k === 'taxRate' ? 'Tax rate must be between 0 and 100.' : 'Tax amount cannot be negative.');
                    }
                    $data[$k] = round($v, 2);
                } else {
                    unset($data[$k]);
                }
            }

            // Validate method
            if (!empty($data['method']) && !in_array($data['method'], ['Cash', 'Card', 'Online', 'COD', 'bKash', 'Nagad', 'Bank'])) {
                throw new HttpException(400, 'Invalid payment method. Must be one of: Cash, Card, Online, COD, bKash, Nagad, Bank');
            }

            // Validate register shift (POS)
            if (!empty($data['registerSessionId'])) {
                $shiftOpen = \App\Models\RegisterSession::where('id', (int) $data['registerSessionId'])
                    ->where('company_id', $companyId)
                    ->where('status', 'open')
                    ->exists();
                if (!$shiftOpen) {
                    throw new HttpException(422, 'The register shift is closed. Open a shift before taking a sale.');
                }
            }

            // Auto-generate invoice number if not provided
            if (empty($data['invoiceNo'])) {
                $data['invoiceNo'] = \App\Support\DocumentNumbers::nextInvoice($companyId)
                    ?? 'INV-' . (int)(microtime(true) * 10000);
            }

            // Check uniqueness of invoice number
            if ($this->repository->invoiceExists($data['invoiceNo'], $companyId)) {
                throw new HttpException(409, 'Invoice number already exists');
            }

            // Auto-set order time if not provided
            if (empty($data['orderTime'])) {
                $data['orderTime'] = now();
            }

            // Resolve shipping address
            $this->resolveShippingAddress($data);

            // Process items
            $items = $data['items'] ?? [];
            $totalCost = 0;
            $priceDelta = 0.0; // change the server made to the line totals the client priced

            foreach ($items as &$item) {
                // Resolve unit price (allow both 'unitPrice' and 'price')
                if (empty($item['unitPrice']) && !empty($item['price'])) {
                    $item['unitPrice'] = $item['price'];
                }

                // Price check: expired offers are ignored, wholesale kicks in from the minimum quantity
                $beforeGross = (float) ($item['unitPrice'] ?? 0) * (float) ($item['quantity'] ?? 0);
                $this->applyServerPrice($companyId, $item);
                $priceDelta += (float) ($item['unitPrice'] ?? 0) * (float) ($item['quantity'] ?? 0) - $beforeGross;

                // Calculate totals (per-line discount comes off the line)
                $lineGross = ($item['unitPrice'] ?? 0) * ($item['quantity'] ?? 0);
                $item['discount'] = round((float) ($item['discount'] ?? 0), 2);
                if ($item['discount'] > $lineGross + 0.005) {
                    throw new HttpException(422, 'A line discount cannot be more than the line total.');
                }
                $item['totalPrice'] = $lineGross - $item['discount'];

                // Snapshot cost price
                if ($item['variantId'] ?? null) {
                    $variant = ProductVariant::find($item['variantId']);
                    $item['unitCost'] = $variant?->cost_price ?? 0;
                } elseif ($item['productId'] ?? null) {
                    $product = Product::find($item['productId']);
                    $item['unitCost'] = $product?->cost_price ?? 0;
                }

                $item['totalCost'] = ($item['unitCost'] ?? 0) * ($item['quantity'] ?? 0);
                $totalCost += $item['totalCost'];
            }
            unset($item);

            // Keep the order total in step with any line the server re-priced (wholesale)
            if (abs($priceDelta) > 0.004) {
                $data['amount'] = round(max(0, (float) ($data['amount'] ?? 0) + $priceDelta), 2);
            }

            // Coupon: re-validate against the cart and compute the discount here
            $this->applyServerCoupon($companyId, $data, $items);

            // VIP auto discount: a VIP customer's sale gets the group discount (Customer::VIP_DISCOUNT_PERCENT)
            // automatically, but ONLY when the request did not specify a discount itself.
            if (!empty($data['customerId']) && (float) ($data['discount'] ?? 0) <= 0) {
                $isVip = \App\Models\Customer::where('company_id', $companyId)
                    ->where('id', (int) $data['customerId'])->where('customer_group', 'vip')->exists();
                if ($isVip) {
                    $vipSubtotal = (float) array_sum(array_column($items, 'totalPrice'));
                    $vipDiscount = round($vipSubtotal * \App\Models\Customer::VIP_DISCOUNT_PERCENT / 100, 2);
                    if ($vipDiscount > 0 && (float) ($data['amount'] ?? 0) >= $vipDiscount) {
                        $data['discount'] = $vipDiscount;
                        $data['amount'] = round((float) $data['amount'] - $vipDiscount, 2);
                    }
                }
            }

            // Discounts above 10% of the subtotal need the role's `discount` flag
            $discountAmount = (float) ($data['discount'] ?? 0);
            if ($discountAmount > 0) {
                $subtotal = (float) array_sum(array_column($items, 'totalPrice'));
                if ($subtotal <= 0) {
                    $subtotal = (float) ($data['amount'] ?? 0) + $discountAmount;
                }
                if ($discountAmount > $subtotal * 0.10 + 0.005) {
                    \App\Support\Sensitive::require('discount', 'You do not have permission to give discounts above 10%');
                }
            }

            // Set computed fields
            $data['company_id'] = $companyId;
            $data['total_cost'] = $totalCost;
            $data['gross_profit'] = ($data['amount'] ?? 0) - $totalCost;
            $data['status'] = $data['status'] ?? 'Pending';
            $data['method'] = $data['method'] ?? 'Cash';
            $data['stock_deducted'] = false;
            if ($courier !== '') {
                $data['carrier'] = $courier;
            }
            if ($initialFulfillment) {
                $data['fulfillmentStatus'] = $initialFulfillment;
            }

            // Initial payment is recorded through due_payments after the sell exists
            $hasInitialPayment = array_key_exists('paidAmount', $data) && $data['paidAmount'] !== null;
            $initialPaid = $hasInitialPayment ? (float) $data['paidAmount'] : 0.0;
            if ($initialPaid > (float) ($data['amount'] ?? 0)) {
                throw new HttpException(422, 'Paid amount cannot exceed the sale total');
            }
            $paymentReference = $data['paymentTransactionId'] ?? null;
            unset($data['paidAmount']);

            // Convert camelCase to snake_case
            $sellData = $this->mapInputToDb($data);

            // Create sell
            $sell = $this->repository->create($sellData);

            // Create order items
            foreach ($items as $item) {
                $item['sell_id'] = $sell->id;
                OrderItem::create([
                    'sell_id' => $item['sell_id'],
                    'product_id' => $item['productId'] ?? null,
                    'variant_id' => $item['variantId'] ?? null,
                    'inventory_id' => $item['inventoryId'] ?? null,
                    'product_name' => $item['productName'],
                    'variant_name' => $item['variantName'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unitPrice'],
                    'discount' => $item['discount'] ?? 0,
                    'total_price' => $item['totalPrice'],
                    'unit_cost' => $item['unitCost'] ?? 0,
                    'total_cost' => $item['totalCost'] ?? 0,
                ]);
            }

            // Record the initial payment (only when the client sent paidAmount, so older
            // callers such as the storefront keep their existing behaviour)
            if ($hasInitialPayment) {
                if ($initialPaid > 0) {
                    $sell->payments()->create([
                        'company_id'     => $companyId,
                        'direction'      => 'in',
                        'amount'         => $initialPaid,
                        'payment_method' => $sell->method,
                        'payment_date'   => now()->toDateString(),
                        'reference'      => $paymentReference,
                        'notes'          => 'Payment at sale creation',
                    ]);
                }
                $sell->recalculate();
            } else {
                // No initial payment sent: still set due = amount so the sale shows up in dues.
                // (The storefront does not go through this service.)
                $sell->recalculate();
            }

            // Deduct stock
            $this->deductStock($sell, $items);

            // Mark stock as deducted
            $sell->update(['stock_deducted' => true]);

            // Orders going out with a courier get a linked shipment
            if ($courier !== '') {
                $this->createLinkedShipment($sell->fresh(), $companyId, $courier, $initialFulfillment);
            }

            // Handle coupon usage
            if ($sell->coupon_id) {
                CouponUsage::create([
                    'coupon_id' => $sell->coupon_id,
                    'customer_id' => $sell->customer_id,
                    'sell_id' => $sell->id,
                    'coupon_code' => $sell->coupon?->code,
                    'discount_applied' => $sell->discount ?? 0,
                    'original_amount' => $sell->amount + ($sell->discount ?? 0),
                    'final_amount' => $sell->amount,
                    'used_at' => now(),
                ]);
                Coupon::where('id', $sell->coupon_id)->increment('times_used');

                $this->notificationService->notifyCouponUsed(
                    $companyId,
                    $sell->coupon?->code ?? 'unknown',
                    $sell->invoice_no
                );
            }

            // Notify new order
            $this->notificationService->notifyOrderPlaced(
                $companyId,
                $sell->invoice_no,
                $sell->customer_name,
                $sell->amount
            );

            return $this->mapper->toDTO($sell->fresh(['customer', 'shippingAddress', 'coupon', 'items', 'shipments']));
        });
    }

    /**
     * Server-side line pricing. Sets wholesale when the quantity qualifies and rejects a client
     * price below every currently valid price (e.g. an offer that has since expired).
     * Custom lines without a product are left alone.
     */
    private function applyServerPrice(int $companyId, array &$item): void
    {
        if (empty($item['productId'])) {
            return;
        }
        $product = Product::where('company_id', $companyId)->find((int) $item['productId']);
        if (!$product) {
            return;
        }
        $variant = !empty($item['variantId']) ? ProductVariant::where('product_id', $product->id)->find((int) $item['variantId']) : null;
        $qty = (float) ($item['quantity'] ?? 0);
        $priced = \App\Services\Pricing\PriceService::resolve($product, $variant, $qty);

        $client = round((float) ($item['unitPrice'] ?? 0), 2);
        $lowest = min($priced['valid']);
        if ($client + 0.011 < $lowest) {
            throw new HttpException(422, 'Price is out of date for ' . ($item['productName'] ?? $product->name) . '. Refresh the product and try again.');
        }
        // Wholesale quantity reached: the wholesale price wins unless the client already went lower
        if ($priced['wholesale'] !== null && $client > $priced['wholesale'] + 0.004) {
            $item['unitPrice'] = $priced['wholesale'];
            $item['price'] = $priced['wholesale'];
        }
    }

    /**
     * Re-validate the sale's coupon against the cart and take the discount from the server.
     * The posted `discount` is the manual discount plus the coupon's; `manualDiscount` (when sent)
     * is the manual part. Rejects with 422 when the coupon does not apply.
     */
    private function applyServerCoupon(int $companyId, array &$data, array $items): void
    {
        if (empty($data['couponId']) && empty($data['couponCode'])) {
            return;
        }
        $coupon = !empty($data['couponId'])
            ? Coupon::where('company_id', $companyId)->where('id', (int) $data['couponId'])->lockForUpdate()->first()
            : Coupon::where('company_id', $companyId)->where('code', $data['couponCode'])->lockForUpdate()->first();
        if (!$coupon) {
            throw new HttpException(422, 'This coupon does not exist');
        }

        $subtotal = round((float) array_sum(array_column($items, 'totalPrice')), 2);
        $isStore = ($data['source'] ?? null) === 'storefront';
        try {
            $result = app(\App\Services\Coupon\CouponService::class)->validate($companyId, [
                'code' => $coupon->code,
                'orderAmount' => $subtotal,
                'customerId' => !empty($data['customerId']) ? (int) $data['customerId'] : null,
                'customerEmail' => $data['shippingEmail'] ?? $data['customerEmail'] ?? null,
                'channel' => $isStore ? 'online' : 'pos',
                'items' => array_map(fn ($i) => [
                    'productId' => $i['productId'] ?? null,
                    'totalPrice' => $i['totalPrice'] ?? 0,
                ], $items),
            ]);
        } catch (HttpException $e) {
            $decoded = json_decode($e->getMessage(), true);
            throw new HttpException($e->getStatusCode(), is_array($decoded) ? ($decoded['message'] ?? 'Coupon is not valid') : $e->getMessage());
        }

        $couponDiscount = (float) $result['discountAmount'];
        $clientDiscount = (float) ($data['discount'] ?? 0);
        $manual = array_key_exists('manualDiscount', $data)
            ? max(0, (float) $data['manualDiscount'])
            : max(0, $clientDiscount - $couponDiscount);
        $newDiscount = round(min($manual + $couponDiscount, $subtotal), 2);

        $data['couponId'] = $coupon->id;
        $data['couponCode'] = $coupon->code;
        $data['discount'] = $newDiscount;
        // The order total moves with the discount the server settled on
        $data['amount'] = round(max(0, (float) ($data['amount'] ?? 0) + $clientDiscount - $newDiscount), 2);
        unset($data['manualDiscount']);
    }

    /**
     * Auto-create the shipment for a delivered sale (blank tracking number is generated).
     * COD orders collect the outstanding balance on delivery.
     */
    private function createLinkedShipment(Sell $sell, int $companyId, string $courier, ?string $stage): void
    {
        $sell->recalculate();
        $status = $sell->status === 'Delivered' ? 'delivered' : ($stage === 'shipped' ? 'in_transit' : 'pending');
        $isCod = strcasecmp((string) $sell->method, 'COD') === 0;
        app(\App\Services\Shipping\OrderShipmentService::class)->create($companyId, [
            'sellId' => $sell->id,
            'carrier' => $courier,
            'shippingMethod' => $sell->shipping_method,
            'status' => $status,
            'shippingCost' => (float) $sell->shipping_cost,
            'codAmount' => $isCod ? (float) $sell->due_amount : 0,
            'shippedAt' => $status === 'in_transit' ? now() : null,
        ]);
        // The shipment sync resets the fulfillment stage; restore the stage picked at checkout
        if ($stage && $sell->status !== 'Delivered') {
            $sell->update(['fulfillment_status' => $stage]);
        }
    }

    /**
     * Update a sell (no stock changes)
     */
    public function update(int $id, int $companyId, array $data): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }

        // Only allow certain fields to be updated
        $allowedFields = ['invoiceNo', 'orderTime', 'customerId', 'customerName', 'method', 'amount', 'shippingCost', 'discount', 'status', 'notes', 'transactionId', 'zoneName', 'courier', 'deliveryNote'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (!empty($updateData)) {
            $dbData = $this->mapInputToDb($updateData);
            $this->repository->update($id, $dbData);
            $sell = $this->repository->findByIdAndCompany($id, $companyId);
        }

        return $this->mapper->toDTO($sell);
    }

    /**
     * Update sell status
     */
    public function updateStatus(int $id, int $companyId, string $status): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }

        if (!in_array($status, ['Pending', 'Processing', 'Delivered', 'Cancelled'])) {
            throw new HttpException(400, 'Invalid status. Must be one of: Pending, Processing, Delivered, Cancelled');
        }

        $oldStatus = $sell->status;
        if ($oldStatus === 'Cancelled' && $status !== 'Cancelled') {
            throw new HttpException(422, 'A cancelled order cannot be reopened. Create a new sale instead.');
        }

        if ($oldStatus === 'Delivered' && $status !== 'Delivered' && $status !== 'Cancelled') {
            throw new HttpException(422, 'A delivered order cannot be moved back to ' . $status . '. Use a return instead.');
        }
        if ($status === 'Pending' && $oldStatus !== 'Pending') {
            $liveShipment = $sell->shipments->first(fn ($sh) => $sh->isLive());
            if ($liveShipment) {
                throw new HttpException(422, 'This order has an active shipment. Cancel the shipment before moving the order back to Pending.');
            }
        }

        if ($status === 'Pending' && $oldStatus !== 'Pending') {
            $this->repository->update($id, ['status' => 'Pending', 'fulfillment_status' => 'unfulfilled', 'shipped_at' => null]);
        } elseif ($status === 'Cancelled' && $oldStatus !== 'Cancelled') {
            if ($oldStatus === 'Delivered') {
                throw new HttpException(422, 'A delivered order cannot be cancelled. Use a return instead.');
            }
            \App\Support\Sensitive::require('void', 'You do not have permission to void sales');
            DB::transaction(function () use ($sell, $id) {
                // Put the stock back and stop chasing money for an order that will not happen
                if ($sell->stock_deducted) {
                    $this->restoreStock($sell);
                }
                $this->repository->update($id, [
                    'status' => 'Cancelled',
                    'stock_deducted' => false,
                    'due_amount' => 0,
                    'fulfillment_status' => 'unfulfilled',
                ]);
            });
        } elseif ($status === 'Delivered' && $oldStatus !== 'Delivered') {
            DB::transaction(function () use ($sell, $id, $companyId) {
                // Close out live shipments through the shipment service so tracking history and COD collection stay in sync
                $shipmentService = app(\App\Services\Shipping\OrderShipmentService::class);
                foreach ($sell->shipments as $shipment) {
                    if ($shipment->isOpen()) {
                        $shipmentService->updateStatus($shipment->id, $companyId, ['status' => 'delivered']);
                    }
                }
                $this->repository->update($id, [
                    'status' => 'Delivered',
                    'fulfillment_status' => 'delivered',
                    'delivered_at' => now(),
                ]);
            });
        } else {
            $this->repository->update($id, ['status' => $status]);
        }
        $sell = $this->repository->findByIdAndCompany($id, $companyId);

        if ($oldStatus !== $status) {
            $this->notificationService->notifyOrderStatusChanged(
                $companyId,
                $sell->invoice_no,
                $oldStatus,
                $status
            );
        }

        return $this->mapper->toDTO($sell);
    }

    /**
     * Mark an order as packed (or put it back to unfulfilled). Shipped / delivered stages are
     * driven by shipments and the order status, so they are not set here.
     */
    public function updateFulfillment(int $id, int $companyId, string $value): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }
        if (!in_array($value, ['packed', 'shipped', 'unfulfilled'], true)) {
            throw new HttpException(400, 'Invalid fulfillment status. Must be one of: packed, shipped, unfulfilled');
        }
        if ($sell->status === 'Cancelled') {
            throw new HttpException(422, 'A cancelled order cannot be changed');
        }
        if ($sell->status === 'Delivered') {
            throw new HttpException(422, 'A delivered order cannot be changed');
        }
        if ($sell->fulfillment_status === 'shipped' && $value !== 'shipped') {
            throw new HttpException(422, 'This order has already been shipped');
        }

        if ($value === 'shipped') {
            DB::transaction(function () use ($sell, $id, $companyId) {
                $shipmentService = app(\App\Services\Shipping\OrderShipmentService::class);
                $live = $sell->shipments->first(fn ($sh) => $sh->isOpen());
                $isCod = strcasecmp((string) $sell->method, 'COD') === 0;
                if ($live) {
                    if (in_array($live->status, ['pending', null, ''], true)) {
                        $shipmentService->updateStatus($live->id, $companyId, ['status' => 'in_transit', 'description' => 'Handed to courier']);
                    }
                } else {
                    // No shipment yet: hand the order to a courier by creating a linked one
                    $shipmentService->create($companyId, [
                        'sellId' => $sell->id,
                        'carrier' => $sell->carrier ?: 'Own delivery',
                        'status' => 'in_transit',
                        'shippedAt' => now(),
                        'codAmount' => $isCod ? (float) $sell->due_amount : 0,
                    ]);
                }
                $update = ['fulfillment_status' => 'shipped', 'shipped_at' => $sell->shipped_at ?: now()];
                // Shipping an order means it has been confirmed
                if ($sell->status === 'Pending') {
                    $update['status'] = 'Processing';
                }
                $this->repository->update($id, $update);
            });
        } else {
            $update = ['fulfillment_status' => $value];
            // Packing an order means it has been confirmed
            if ($value === 'packed' && $sell->status === 'Pending') {
                $update['status'] = 'Processing';
            }
            $this->repository->update($id, $update);
        }

        return $this->mapper->toDTO($this->repository->findByIdAndCompany($id, $companyId));
    }

    /**
     * Delete a sell and restore stock
     */
    public function delete(int $id, int $companyId): void
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }

        if ($sell->status !== 'Cancelled') {
            throw new HttpException(422, 'Only cancelled orders can be deleted. Cancel the order first.');
        }
        if ($sell->payments()->exists() || (float) $sell->paid_amount > 0) {
            throw new HttpException(422, 'This order has recorded payments and cannot be deleted.');
        }
        if (\App\Models\CustomerReturn::where('company_id', $companyId)->where('order_id', $id)->exists()) {
            throw new HttpException(422, 'This order has customer returns and cannot be deleted.');
        }

        // Restore stock if it was deducted
        if ($sell->stock_deducted) {
            $this->restoreStock($sell);
        }

        $this->repository->delete($id);
    }

    /**
     * Get statistics
     */
    public function getStats(int $companyId, ?string $source = null): array
    {
        return $this->repository->getStats($companyId, $source);
    }

    public function getWeeklyOrders(int $companyId): array
    {
        return $this->repository->getWeeklyOrders($companyId);
    }

    public function getMonthlyRevenue(int $companyId): array
    {
        return $this->repository->getMonthlyRevenue($companyId);
    }

    /**
     * Get customer dues (sells with outstanding balance) with aging buckets
     */
    public function getCustomerDues(int $companyId, array $filters): array
    {
        $paginated = $this->repository->getCustomerDues($companyId, $filters);

        $data = [];
        foreach ($paginated->items() as $sell) {
            $data[] = [
                'id' => $sell->id,
                'invoiceNo' => $sell->invoice_no,
                'customerId' => $sell->customer_id,
                'customerName' => $sell->customer_name,
                'orderTime' => $sell->order_time?->toIso8601String(),
                'amount' => (float) $sell->amount,
                'paidAmount' => (float) $sell->paid_amount,
                'dueAmount' => (float) $sell->due_amount,
                'paymentStatus' => $sell->payment_status,
                'agingBucket' => $sell->getAttribute('aging_bucket'),
                'daysOld' => $sell->getAttribute('days_old'),
            ];
        }

        $summary = $this->repository->getCustomerDuesSummary($companyId, $filters);

        return [
            'data' => $data,
            'summary' => $summary,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    /**
     * Deduct stock for sell items
     */
    private function deductStock(Sell $sell, array $items): void
    {
        $locStock = app(\App\Services\Inventory\LocationStockService::class);
        $locationId = $this->resolveStockLocation($sell, $locStock);

        // Per-location stock: check every line (repeated lines add up) before touching anything
        if ($locationId) {
            $need = [];
            foreach ($items as $item) {
                $p = isset($item['productId']) ? Product::find($item['productId']) : null;
                if (!$p || $item['quantity'] <= 0 || !$this->usesLocationStock($p, $item['variantId'] ?? null)) {
                    continue;
                }
                $k = $p->id . '|' . ($item['variantId'] ?? '');
                $need[$k] = [$p, $item['variantId'] ?? null, ($need[$k][2] ?? 0) + (int) $item['quantity']];
            }
            foreach ($need as [$p, $vid, $qty]) {
                $locStock->assertAvailableAt($p, $vid ? (int) $vid : null, $locationId, $qty);
            }
        }

        foreach ($items as $item) {
            if (!isset($item['productId']) || $item['quantity'] <= 0) {
                continue;
            }

            $product = Product::find($item['productId']);

            if ($locationId && $product && $this->usesLocationStock($product, $item['variantId'] ?? null)) {
                $locStock->deduct($sell->company_id, $product, ($item['variantId'] ?? null) ? (int) $item['variantId'] : null, $locationId, (int) $item['quantity'], [
                    'reference_type' => 'sell',
                    'reference_id' => $sell->id,
                    'reason' => 'Sale',
                    'notes' => $sell->invoice_no,
                ]);
                $product->refresh();
                if (!$product->is_bundle && $product->reorder_point > 0 && $product->stock <= $product->reorder_point) {
                    $this->notificationService->notifyLowStock($sell->company_id, $product->name, $product->stock, $product->reorder_point);
                }
                continue;
            }

            if ($product && $product->is_bundle) {
                $this->deductBundleStock($sell->company_id, $product, (int) $item['quantity']);
            } elseif ($item['variantId'] ?? null) {
                $this->deductVariantStock($sell->company_id, $item);
            } else {
                $this->deductSimpleProductStock($sell->company_id, $item);
            }
        }
    }

    /**
     * Location to take a sale's stock from, or null while the company still uses flat (legacy) stock.
     * Falls back to the default location and records it on the sale.
     */
    private function resolveStockLocation(Sell $sell, \App\Services\Inventory\LocationStockService $locStock): ?int
    {
        $companyId = (int) $sell->company_id;
        if (!$locStock->hasLocationStock($companyId)) {
            return null;
        }
        $locationId = $sell->location_id ? (int) $sell->location_id : $locStock->defaultLocationId($companyId);
        if (!\App\Models\Location::where('company_id', $companyId)->whereKey($locationId)->exists()) {
            throw new HttpException(422, 'Stock location not found');
        }
        if (!$sell->location_id) {
            $sell->update(['location_id' => $locationId]);
        }
        return $locationId;
    }

    /** Serial/batch-tracked items keep their dedicated deduction; everything else is per location. */
    private function usesLocationStock(Product $product, $variantId): bool
    {
        if (($product->tracking_type ?? 'none') !== 'none' && !$product->is_bundle) {
            return false;
        }
        if ($variantId) {
            $v = ProductVariant::find($variantId);
            return $v && ($v->tracking_type ?? 'none') === 'none';
        }
        return true;
    }

    /**
     * Deduct variant stock
     */
    private function deductVariantStock(int $companyId, array $item): void
    {
        $variant = ProductVariant::find($item['variantId']);
        if (!$variant) {
            throw new HttpException(400, 'Variant not found');
        }

        if ($variant->stock < $item['quantity']) {
            throw new HttpException(400, "Insufficient stock for variant '{$variant->name}' (available: {$variant->stock}, requested: {$item['quantity']})");
        }

        // Deduct from inventory
        if ($item['inventoryId'] ?? null) {
            // Deduct from specific inventory row
            $inventory = VariantInventory::find($item['inventoryId']);
            if (!$inventory || $inventory->quantity < $item['quantity']) {
                throw new HttpException(400, "Insufficient stock in specified inventory");
            }
            $inventory->decrement('quantity', $item['quantity']);
        } else {
            // Deduct from any available inventory (highest quantity first)
            $inventories = VariantInventory::where('variant_id', $variant->id)
                ->orderBy('quantity', 'desc')
                ->get();

            $remaining = $item['quantity'];
            foreach ($inventories as $inventory) {
                if ($remaining <= 0) {
                    break;
                }
                $toDeduct = min($inventory->quantity, $remaining);
                $inventory->decrement('quantity', $toDeduct);
                $remaining -= $toDeduct;
            }

            if ($remaining > 0) {
                throw new HttpException(400, "Insufficient stock for variant '{$variant->name}'");
            }
        }

        // Serial tracking for variant
        if ($variant->tracking_type === 'serial') {
            $serials = ProductSerial::where('variant_id', $variant->id)
                ->where('status', 'available')
                ->orderBy('received_date')->orderBy('id')
                ->limit($item['quantity'])->get();

            if ($serials->count() < $item['quantity']) {
                throw new HttpException(400, "Insufficient available serial numbers for variant '{$variant->name}'");
            }
            foreach ($serials as $serial) {
                $serial->update(['status' => 'sold', 'sold_date' => now()->toDateString()]);
            }
            $variant->stock = ProductSerial::where('variant_id', $variant->id)->where('status', 'available')->count();
            $variant->save();

        // Batch tracking for variant
        } elseif ($variant->tracking_type === 'batch') {
            $remaining = $item['quantity'];
            $batches = ProductBatch::where('variant_id', $variant->id)
                ->where('quantity_remaining', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('id')->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $deduct = min($batch->quantity_remaining, $remaining);
                $batch->decrement('quantity_remaining', $deduct);
                $remaining -= $deduct;
            }
            if ($remaining > 0) {
                throw new HttpException(400, "Insufficient batch stock for variant '{$variant->name}'");
            }
            $variant->stock = ProductBatch::where('variant_id', $variant->id)->sum('quantity_remaining');
            $variant->save();
        }

        // Sync variant stock (non-tracked path uses VariantInventory)
        if ($variant->tracking_type === 'none') {
            $variant->stock = VariantInventory::where('variant_id', $variant->id)->sum('quantity');
            $variant->save();
        }

        // Sync product stock
        $product = $variant->product;
        $product->stock = ProductVariant::where('product_id', $product->id)->sum('stock');
        $product->save();

        // Low stock alert for variant
        if ($variant->reorder_point > 0 && $variant->stock <= $variant->reorder_point) {
            $label = $product->name . ' (' . $variant->name . ')';
            $this->notificationService->notifyLowStock($companyId, $label, $variant->stock, $variant->reorder_point);
        }
    }

    /**
     * Deduct simple product stock
     */
    private function deductSimpleProductStock(int $companyId, array $item): void
    {
        $product = Product::find($item['productId']);
        if (!$product) {
            throw new HttpException(400, 'Product not found');
        }

        if ($product->stock < $item['quantity']) {
            throw new HttpException(400, "Insufficient stock for product '{$product->name}' (available: {$product->stock}, requested: {$item['quantity']})");
        }

        // Serial tracking: mark serials as sold (FIFO by received_date)
        if ($product->tracking_type === 'serial') {
            $serials = ProductSerial::where('product_id', $product->id)
                ->where('status', 'available')
                ->whereNull('variant_id')
                ->orderBy('received_date')
                ->orderBy('id')
                ->limit($item['quantity'])
                ->get();

            if ($serials->count() < $item['quantity']) {
                throw new HttpException(400, "Insufficient available serial numbers for '{$product->name}'");
            }

            foreach ($serials as $serial) {
                $serial->update(['status' => 'sold', 'sold_date' => now()->toDateString()]);
            }

            $available = ProductSerial::where('product_id', $product->id)
                ->whereNull('variant_id')->where('status', 'available')->count();
            $product->update(['stock' => $available]);

        // Batch tracking: deduct from oldest expiry (FEFO)
        } elseif ($product->tracking_type === 'batch') {
            $remaining = $item['quantity'];
            $batches = ProductBatch::where('product_id', $product->id)
                ->whereNull('variant_id')
                ->where('quantity_remaining', '>', 0)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->orderBy('id')
                ->get();

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;
                $deduct = min($batch->quantity_remaining, $remaining);
                $batch->decrement('quantity_remaining', $deduct);
                $remaining -= $deduct;
            }

            if ($remaining > 0) {
                throw new HttpException(400, "Insufficient batch stock for '{$product->name}'");
            }

            $totalRemaining = ProductBatch::where('product_id', $product->id)
                ->whereNull('variant_id')->sum('quantity_remaining');
            $product->update(['stock' => $totalRemaining]);

        } else {
            $product->decrement('stock', $item['quantity']);
        }

        // Low stock alert
        $product->refresh();
        if ($product->reorder_point > 0 && $product->stock <= $product->reorder_point) {
            $this->notificationService->notifyLowStock($companyId, $product->name, $product->stock, $product->reorder_point);
        }
    }

    private function deductBundleStock(int $companyId, Product $bundle, int $saleQty): void
    {
        $bundleItems = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        foreach ($bundleItems as $bi) {
            $totalQty = $bi->quantity * $saleQty;

            if ($bi->variant_id && $bi->variant) {
                $this->deductVariantStock($companyId, [
                    'productId' => $bi->product_id,
                    'variantId' => $bi->variant_id,
                    'quantity'  => $totalQty,
                ]);
            } elseif ($bi->product) {
                $this->deductSimpleProductStock($companyId, [
                    'productId' => $bi->product_id,
                    'quantity'  => $totalQty,
                ]);
            }
        }

        // Re-sync bundle stock after child deductions
        $bundle->refresh();
        $items = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        $available = null;
        foreach ($items as $item) {
            $childStock = $item->variant_id && $item->variant
                ? (int) $item->variant->stock
                : (int) ($item->product->stock ?? 0);
            $slots = (int) floor($childStock / max(1, $item->quantity));
            $available = $available === null ? $slots : min($available, $slots);
        }
        $bundle->update(['stock' => max(0, $available ?? 0)]);
    }

    /**
     * Restore stock when sell is deleted
     */
    private function restoreStock(Sell $sell): void
    {
        // Sales that took stock from a location give it back to that same location
        $reversed = app(\App\Services\Inventory\LocationStockService::class)->reverseSale((int) $sell->company_id, (int) $sell->id);

        foreach ($sell->items as $item) {
            $product = Product::find($item->product_id);
            if ($reversed && $product && $this->usesLocationStock($product, $item->variant_id)) {
                continue;
            }

            if ($product && $product->is_bundle) {
                $this->restoreBundleStock($product, (int) $item->quantity);
            } elseif ($item->variant_id) {
                $this->restoreVariantStock($item);
            } else {
                $this->restoreSimpleProductStock($item);
            }
        }
    }

    private function restoreBundleStock(Product $bundle, int $saleQty): void
    {
        $bundleItems = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        foreach ($bundleItems as $bi) {
            $restoreQty = $bi->quantity * $saleQty;

            if ($bi->variant_id && $bi->variant) {
                $bi->variant->increment('stock', $restoreQty);
                $childProduct = $bi->variant->product;
                if ($childProduct) {
                    $childProduct->stock = ProductVariant::where('product_id', $childProduct->id)->sum('stock');
                    $childProduct->save();
                }
            } elseif ($bi->product) {
                $bi->product->increment('stock', $restoreQty);
            }
        }

        // Re-sync bundle stock after restoring children
        $bundle->refresh();
        $items = ProductBundleItem::where('bundle_product_id', $bundle->id)
            ->with(['product', 'variant'])
            ->get();

        $available = null;
        foreach ($items as $item) {
            $childStock = $item->variant_id && $item->variant
                ? (int) $item->variant->stock
                : (int) ($item->product->stock ?? 0);
            $slots = (int) floor($childStock / max(1, $item->quantity));
            $available = $available === null ? $slots : min($available, $slots);
        }
        $bundle->update(['stock' => max(0, $available ?? 0)]);
    }

    /**
     * Restore variant stock
     */
    private function restoreVariantStock(OrderItem $item): void
    {
        $variant = ProductVariant::find($item->variant_id);
        if (!$variant) {
            return;
        }

        // Restore to inventory at same location
        if ($item->inventory_id) {
            $inventory = VariantInventory::find($item->inventory_id);
            if ($inventory) {
                $inventory->increment('quantity', $item->quantity);
            }
        } else {
            // Restore to product's current location (or any inventory)
            $inventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $variant->product->location_id)
                ->first();

            if ($inventory) {
                $inventory->increment('quantity', $item->quantity);
            } else {
                VariantInventory::create([
                    'variant_id' => $variant->id,
                    'location_id' => $variant->product->location_id,
                    'quantity' => $item->quantity,
                ]);
            }
        }

        // Sync variant stock
        $variant->stock = VariantInventory::where('variant_id', $variant->id)->sum('quantity');
        $variant->save();

        // Sync product stock
        $product = $variant->product;
        $product->stock = ProductVariant::where('product_id', $product->id)->sum('stock');
        $product->save();
    }

    /**
     * Restore simple product stock
     */
    private function restoreSimpleProductStock(OrderItem $item): void
    {
        $product = Product::find($item->product_id);
        if ($product) {
            $product->increment('stock', $item->quantity);
        }
    }

    /**
     * Resolve shipping address and populate fields
     */
    private function resolveShippingAddress(array &$data): void
    {
        // Path 1: Inline custom address
        if (!empty($data['shippingFullName']) && !empty($data['shippingAddressLine1'])) {
            if (empty($data['shippingCity']) || empty($data['shippingCountry'])) {
                throw new HttpException(400, 'Shipping city and country are required for inline address');
            }
            $data['shipping_address_type'] = $data['shippingAddressType'] ?? 'other';
            return;
        }

        // Path 2: Saved address by ID
        if (!empty($data['shippingAddressId'])) {
            $address = ShippingAddress::find($data['shippingAddressId']);
            if (!$address) {
                throw new HttpException(400, 'Shipping address not found');
            }
            if ($address->customer_id !== ($data['customerId'] ?? null)) {
                throw new HttpException(400, 'Shipping address does not belong to this customer');
            }
            $data['shipping_full_name'] = $address->full_name;
            $data['shipping_phone'] = $address->phone ?? '';
            $data['shipping_email'] = $address->email ?? '';
            $data['shipping_address_line1'] = $address->address_line1;
            $data['shipping_address_line2'] = $address->address_line2;
            $data['shipping_city'] = $address->city;
            $data['shipping_state'] = $address->state;
            $data['shipping_postal_code'] = $address->postal_code;
            $data['shipping_country'] = $address->country;
            $data['shipping_address_type'] = $address->type;
            return;
        }

        // Path 3: Customer default address
        if (!empty($data['customerId'])) {
            $address = ShippingAddress::where('customer_id', $data['customerId'])
                ->where('is_default', true)
                ->first();
            if ($address) {
                $data['shipping_address_id'] = $address->id;
                $data['shipping_full_name'] = $address->full_name;
                $data['shipping_phone'] = $address->phone ?? '';
                $data['shipping_email'] = $address->email ?? '';
                $data['shipping_address_line1'] = $address->address_line1;
                $data['shipping_address_line2'] = $address->address_line2;
                $data['shipping_city'] = $address->city;
                $data['shipping_state'] = $address->state;
                $data['shipping_postal_code'] = $address->postal_code;
                $data['shipping_country'] = $address->country;
                $data['shipping_address_type'] = $address->type;
            }
        }
    }

    /**
     * Map camelCase input to snake_case database fields
     */
    private function mapInputToDb(array $data): array
    {
        $map = [
            'invoiceNo' => 'invoice_no',
            'orderTime' => 'order_time',
            'customerId' => 'customer_id',
            'customerName' => 'customer_name',
            'shippingAddressId' => 'shipping_address_id',
            'shippingFullName' => 'shipping_full_name',
            'shippingPhone' => 'shipping_phone',
            'shippingEmail' => 'shipping_email',
            'shippingAddressLine1' => 'shipping_address_line1',
            'shippingAddressLine2' => 'shipping_address_line2',
            'shippingCity' => 'shipping_city',
            'shippingState' => 'shipping_state',
            'shippingPostalCode' => 'shipping_postal_code',
            'shippingCountry' => 'shipping_country',
            'shippingAddressType' => 'shipping_address_type',
            'shippingCost' => 'shipping_cost',
            'locationId' => 'location_id',
            'registerSessionId' => 'register_session_id',
            'shippingMethod' => 'shipping_method',
            'paymentTransactionId' => 'payment_transaction_id',
            'transactionId' => 'transaction_id',
            'zoneName' => 'zone_name',
            'deliveryNote' => 'delivery_note',
            'taxRate' => 'tax_rate',
            'taxAmount' => 'tax_amount',
            'courier' => 'carrier',
            'couponId' => 'coupon_id',
            'couponCode' => 'coupon_code',
            'paymentStatus' => 'payment_status',
            'fulfillmentStatus' => 'fulfillment_status',
            'trackingNumber' => 'tracking_number',
            'stockDeducted' => 'stock_deducted',
            'totalCost' => 'total_cost',
            'grossProfit' => 'gross_profit',
            'shippedAt' => 'shipped_at',
            'deliveredAt' => 'delivered_at',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $dbKey = $map[$key] ?? $key;
            $result[$dbKey] = $value;
        }

        return $result;
    }
}
