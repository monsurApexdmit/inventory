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
            if (!empty($data['status']) && !in_array($data['status'], ['Pending', 'Processing', 'Delivered'])) {
                throw new HttpException(400, 'Invalid status. Must be one of: Pending, Processing, Delivered');
            }

            // Validate method
            if (!empty($data['method']) && !in_array($data['method'], ['Cash', 'Card', 'Online'])) {
                throw new HttpException(400, 'Invalid payment method. Must be one of: Cash, Card, Online');
            }

            // Auto-generate invoice number if not provided
            if (empty($data['invoiceNo'])) {
                $data['invoiceNo'] = 'INV-' . (int)(microtime(true) * 10000);
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

            foreach ($items as &$item) {
                // Resolve unit price (allow both 'unitPrice' and 'price')
                if (empty($item['unitPrice']) && !empty($item['price'])) {
                    $item['unitPrice'] = $item['price'];
                }

                // Calculate totals
                $item['totalPrice'] = ($item['unitPrice'] ?? 0) * ($item['quantity'] ?? 0);

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

            // Set computed fields
            $data['company_id'] = $companyId;
            $data['total_cost'] = $totalCost;
            $data['gross_profit'] = ($data['amount'] ?? 0) - $totalCost;
            $data['status'] = $data['status'] ?? 'Pending';
            $data['method'] = $data['method'] ?? 'Cash';
            $data['stock_deducted'] = false;

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
                    'total_price' => $item['totalPrice'],
                    'unit_cost' => $item['unitCost'] ?? 0,
                    'total_cost' => $item['totalCost'] ?? 0,
                ]);
            }

            // Deduct stock
            $this->deductStock($sell, $items);

            // Mark stock as deducted
            $sell->update(['stock_deducted' => true]);

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
     * Update a sell (no stock changes)
     */
    public function update(int $id, int $companyId, array $data): SellDTO
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }

        // Only allow certain fields to be updated
        $allowedFields = ['invoiceNo', 'orderTime', 'customerId', 'customerName', 'method', 'amount', 'shippingCost', 'discount', 'status', 'notes'];
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

        if (!in_array($status, ['Pending', 'Processing', 'Delivered'])) {
            throw new HttpException(400, 'Invalid status. Must be one of: Pending, Processing, Delivered');
        }

        $oldStatus = $sell->status;
        $this->repository->update($id, ['status' => $status]);
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
     * Delete a sell and restore stock
     */
    public function delete(int $id, int $companyId): void
    {
        $sell = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
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
    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
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
     * Deduct stock for sell items
     */
    private function deductStock(Sell $sell, array $items): void
    {
        foreach ($items as $item) {
            if (!isset($item['productId']) || $item['quantity'] <= 0) {
                continue;
            }

            $product = Product::find($item['productId']);

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
        foreach ($sell->items as $item) {
            $product = Product::find($item->product_id);

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
            'shippingMethod' => 'shipping_method',
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
