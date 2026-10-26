<?php

namespace App\Services\PurchaseOrder;

use App\DTOs\PurchaseOrder\PurchaseOrderDTO;
use App\DTOs\PurchaseOrder\PurchaseOrderMapper;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderItem;
use App\Models\Location;
use App\Models\Vendor;
use App\Services\Inventory\LocationStockService;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderService
{
    private readonly PurchaseOrderMapper $mapper;

    public function __construct(
        private readonly IPurchaseOrderRepository $repository,
        private readonly LocationStockService $stock,
        private readonly \App\Services\Notification\NotificationService $notifications,
    ) {
        $this->mapper = new PurchaseOrderMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);

        return [
            'data'         => array_map(fn($po) => $this->mapper->toDTO($po)->toArray(), $paginated->items()),
            'total'        => $paginated->total(),
            'due_total'    => $this->repository->sumDue($companyId, $filters),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): PurchaseOrderDTO
    {
        $po = $this->repository->findById($id, $companyId);
        if (!$po) {
            throw new HttpException(404, 'Purchase order not found');
        }
        return $this->mapper->toDTO($po);
    }

    public function create(int $companyId, array $data): PurchaseOrderDTO
    {
        $status = $data['status'] ?? 'draft';
        if (!in_array($status, ['draft', 'sent', 'received'], true)) {
            throw new HttpException(422, 'Status must be one of: draft, sent, received');
        }
        if ($status === 'received' && empty($data['locationId'])) {
            throw new HttpException(422, 'A receiving location is required to create an already-received purchase order');
        }

        $this->assertVendorOrderable((int) $data['vendorId'], $companyId);
        if (!empty($data['locationId'])) {
            $this->assertLocation((int) $data['locationId'], $companyId);
        }

        return DB::transaction(function () use ($companyId, $data, $status) {
            $poNumber = $this->repository->nextPoNumber($companyId);

            $po = $this->repository->create([
                'company_id'     => $companyId,
                'vendor_id'      => $data['vendorId'],
                'location_id'    => $data['locationId'] ?? null,
                'po_number'      => $poNumber,
                'status'         => $status === 'received' ? 'sent' : $status,
                'expected_date'  => $data['expectedDate'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'total_amount'   => 0,
                'discount_type'  => $data['discountType'] ?? 'flat',
                'discount_value' => $data['discountValue'] ?? 0,
                'tax_rate'       => $data['taxRate'] ?? 0,
                'shipping_cost'  => $data['shippingCost'] ?? 0,
                'sent_at'        => in_array($status, ['sent', 'received'], true) ? now() : null,
            ]);

            $this->replaceItems($po, $data['items'], false);
            $this->assertDiscountWithinSubtotal($po);
            $total = $this->applyTotals($po);

            $paidAmount = (float) ($data['paidAmount'] ?? 0);
            if (round($paidAmount, 2) > $total) {
                throw new HttpException(422, 'Paid amount cannot exceed the order total');
            }
            if ($paidAmount > 0) {
                $po->payments()->create([
                    'company_id'     => $companyId,
                    'direction'      => 'out',
                    'amount'         => $paidAmount,
                    'payment_method' => $data['paymentMethod'] ?? 'cash',
                    'payment_date'   => $data['paymentDate'] ?? now()->toDateString(),
                    'reference'      => $data['reference'] ?? null,
                    'notes'          => 'Advance payment at order creation',
                ]);
            }
            $po->recalculate();

            if ($status === 'received') {
                $po->load('items.product');
                $lines = $po->items->map(fn($i) => [
                    'itemId' => $i->id,
                    'quantityReceiving' => $i->quantity_ordered,
                ])->all();
                $this->applyReceipt($po, $lines);
            }

            return $this->mapper->toDTO(
                $this->repository->findById($po->id, $companyId)
            );
        });
    }

    public function update(int $id, int $companyId, array $data): PurchaseOrderDTO
    {
        $po = $this->repository->findById($id, $companyId);
        if (!$po) {
            throw new HttpException(404, 'Purchase order not found');
        }

        if (in_array($po->status, ['received', 'cancelled'])) {
            throw new HttpException(422, 'Cannot edit a received or cancelled purchase order');
        }
        if (isset($data['vendorId']) && (int) $data['vendorId'] !== (int) $po->vendor_id) {
            $this->assertVendorOrderable((int) $data['vendorId'], $companyId);
        }
        if (!empty($data['locationId'])) {
            $this->assertLocation((int) $data['locationId'], $companyId);
        }
        if (!empty($data['expectedDate']) && $data['expectedDate'] !== $po->expected_date?->toDateString()
            && \Carbon\Carbon::parse($data['expectedDate'])->lt(now()->subDay()->startOfDay())) {
            throw new HttpException(422, 'Expected delivery cannot be in the past.');
        }

        return DB::transaction(function () use ($po, $companyId, $data) {
            $newStatus = $data['status'] ?? $po->status;

            $po->update([
                'vendor_id'      => $data['vendorId'] ?? $po->vendor_id,
                'location_id'    => $data['locationId'] ?? $po->location_id,
                'status'         => $newStatus,
                'expected_date'  => $data['expectedDate'] ?? $po->expected_date,
                'notes'          => $data['notes'] ?? $po->notes,
                'discount_type'  => $data['discountType'] ?? $po->discount_type,
                'discount_value' => $data['discountValue'] ?? $po->discount_value,
                'tax_rate'       => $data['taxRate'] ?? $po->tax_rate,
                'shipping_cost'  => $data['shippingCost'] ?? $po->shipping_cost,
                'sent_at'        => $po->sent_at ?? ($newStatus === 'sent' ? now() : null),
            ]);

            if (isset($data['items'])) {
                if ($this->receivedUnits($po) > 0) {
                    throw new HttpException(422, "Stock has already been received against {$po->po_number}; its items can no longer be edited.");
                }
                $this->replaceItems($po, $data['items'], true);
            }

            $this->assertDiscountWithinSubtotal($po->refresh());
            $this->applyTotals($po);
            $po->recalculate();

            return $this->mapper->toDTO(
                $this->repository->findById($po->id, $companyId)
            );
        });
    }

    public function receive(int $id, int $companyId, array $data): PurchaseOrderDTO
    {
        $po = $this->repository->findById($id, $companyId);
        if (!$po) {
            throw new HttpException(404, 'Purchase order not found');
        }

        if ($po->status === 'cancelled') {
            throw new HttpException(422, 'Cannot receive a cancelled purchase order');
        }

        if ($po->status === 'received') {
            throw new HttpException(422, 'Purchase order already fully received');
        }

        $locationId = !empty($data['locationId']) ? (int) $data['locationId'] : null;
        if ($locationId) {
            $this->assertLocation($locationId, $companyId);
        }

        $dto = DB::transaction(function () use ($po, $companyId, $data, $locationId) {
            $this->applyReceipt($po, $data['items'], $locationId);

            return $this->mapper->toDTO(
                $this->repository->findById($po->id, $companyId)
            );
        });

        $fresh = $this->repository->findById($po->id, $companyId);
        $this->notifications->notifyPurchaseOrderReceived(
            $companyId, (string) $po->po_number, (string) ($fresh?->vendor?->name ?? 'vendor'), ($fresh?->status ?? '') === 'received'
        );

        return $dto;
    }

    /**
     * Replace the line items of a PO (subtotals computed server-side).
     */
    private function replaceItems(PurchaseOrder $po, array $items, bool $deleteExisting): void
    {
        if ($deleteExisting) {
            $po->items()->delete();
        }
        foreach ($items as $item) {
            $qty = (int) $item['quantityOrdered'];
            $cost = (float) ($item['unitCost'] ?? 0);
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id'        => $item['productId'],
                'variant_id'        => $item['variantId'] ?? null,
                'quantity_ordered'  => $qty,
                'quantity_received' => $item['quantityReceived'] ?? 0,
                'unit_cost'         => $cost,
                'subtotal'          => round($cost * $qty, 2),
            ]);
        }
    }

    /**
     * Recompute total_amount from items + discount/tax/shipping. Returns the total.
     */
    private function applyTotals(PurchaseOrder $po): float
    {
        $subtotal = (float) $po->items()->sum('subtotal');
        $totals = PurchaseOrder::computeTotals(
            $subtotal,
            (string) $po->discount_type,
            (float) $po->discount_value,
            (float) $po->tax_rate,
            (float) $po->shipping_cost,
        );
        $po->update(['total_amount' => $totals['total']]);
        $po->recalculate();

        return $totals['total'];
    }

    /**
     * Receive stock for the given lines, log a receipt and update PO status.
     *
     * @param array<int, array{itemId:int, quantityReceiving:int}> $lines
     */
    private function applyReceipt(PurchaseOrder $po, array $lines, ?int $locationId = null): void
    {
        $companyId = (int) $po->company_id;
        $locationId = $locationId ?: ($po->location_id ? (int) $po->location_id : $this->stock->defaultLocationId($companyId));
        $receiptItems = [];

        foreach ($lines as $received) {
            $item = $po->items()->with('product')->where('id', $received['itemId'])->first();
            if (!$item) {
                continue;
            }

            $qtyReceiving = (int) $received['quantityReceiving'];
            $remaining = $item->quantity_ordered - $item->quantity_received;
            $qtyReceiving = min($qtyReceiving, $remaining);
            if ($qtyReceiving <= 0) {
                continue;
            }

            // "Any variant" lines on a product that has real variants go to its first variant.
            $variantId = $item->variant_id ? (int) $item->variant_id : null;
            if (!$variantId) {
                $variantId = ProductVariant::where('product_id', $item->product_id)->where('name', '<>', 'Default')->orderBy('id')->value('id');
                $variantId = $variantId ? (int) $variantId : null;
            }

            $this->stock->change($companyId, (int) $item->product_id, $variantId, $locationId, $qtyReceiving, [
                'type'           => 'receive',
                'reference_type' => 'purchase_order',
                'reference_id'   => $po->id,
                'unit_cost'      => (float) $item->unit_cost,
                'notes'          => 'Received against ' . $po->po_number,
            ]);

            $item->increment('quantity_received', $qtyReceiving);

            $receiptItems[] = [
                'itemId'      => $item->id,
                'productId'   => $item->product_id,
                'productName' => $item->product?->name,
                'quantity'    => $qtyReceiving,
            ];
        }

        if ($receiptItems) {
            PurchaseOrderReceipt::create([
                'purchase_order_id' => $po->id,
                'location_id'       => $locationId,
                'received_at'       => now(),
                'items'             => $receiptItems,
            ]);
        }

        $po->load('items');
        $allReceived = $po->items->every(fn($i) => $i->quantity_received >= $i->quantity_ordered);
        $anyReceived = $po->items->some(fn($i) => $i->quantity_received > 0);

        $newStatus = $allReceived ? 'received' : ($anyReceived ? 'partial' : $po->status);
        $update = ['status' => $newStatus];
        if ($receiptItems && (int) $po->location_id !== $locationId) {
            $update['location_id'] = $locationId;
        }
        if ($newStatus === 'received' && !$po->received_at) {
            $update['received_at'] = now();
        }
        if ($anyReceived && !$po->sent_at) {
            $update['sent_at'] = now();
        }
        $po->update($update);
    }

    public function updateStatus(int $id, int $companyId, string $status): PurchaseOrderDTO
    {
        $po = $this->repository->findById($id, $companyId);
        if (!$po) {
            throw new HttpException(404, 'Purchase order not found');
        }

        $allowed = ['draft', 'sent', 'cancelled'];
        if (!in_array($status, $allowed)) {
            throw new HttpException(422, "Status must be one of: " . implode(', ', $allowed));
        }

        if (in_array($po->status, ['received', 'cancelled'], true) && $status !== $po->status) {
            throw new HttpException(422, 'A ' . $po->status . ' purchase order cannot change status');
        }
        if ($status === 'cancelled' && $this->receivedUnits($po) > 0) {
            throw new HttpException(422, "Stock has already been received against {$po->po_number}. Use a supplier return instead of cancelling.");
        }
        if ($status === 'draft' && $po->status !== 'draft' && $this->receivedUnits($po) > 0) {
            throw new HttpException(422, 'A purchase order with received stock cannot go back to draft');
        }

        $update = ['status' => $status];
        if ($status === 'sent' && !$po->sent_at) {
            $update['sent_at'] = now();
        }
        $po->update($update);

        return $this->mapper->toDTO(
            $this->repository->findById($po->id, $companyId)
        );
    }

    public function delete(int $id, int $companyId): void
    {
        $po = $this->repository->findById($id, $companyId);
        if (!$po) {
            throw new HttpException(404, 'Purchase order not found');
        }

        if (in_array($po->status, ['partial', 'received']) || $this->receivedUnits($po) > 0) {
            throw new HttpException(422, "Stock has been received against {$po->po_number}, so it cannot be deleted.");
        }
        if ($po->payments()->exists()) {
            throw new HttpException(422, "{$po->po_number} has payments recorded and cannot be deleted.");
        }

        $this->repository->delete($id, $companyId);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    public function getVendorDues(int $companyId, array $filters): array
    {
        $paginated = $this->repository->getVendorDues($companyId, $filters);

        $data = [];
        foreach ($paginated->items() as $po) {
            $data[] = [
                'id' => $po->id,
                'poNumber' => $po->po_number,
                'vendorId' => $po->vendor_id,
                'vendorName' => $po->vendor?->name,
                'expectedDate' => $po->expected_date?->toIso8601String(),
                'totalAmount' => (float) $po->total_amount,
                'paidAmount' => (float) $po->paid_amount,
                'dueAmount' => (float) $po->due_amount,
                'paymentStatus' => $po->payment_status,
                'agingBucket' => $po->getAttribute('aging_bucket'),
                'daysOld' => $po->getAttribute('days_old'),
            ];
        }

        $summary = $this->repository->getVendorDuesSummary($companyId, $filters);

        return [
            'data' => $data,
            'summary' => $summary,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    private function receivedUnits(PurchaseOrder $po): int
    {
        return (int) $po->items()->sum('quantity_received');
    }

    private function assertVendorOrderable(int $vendorId, int $companyId): void
    {
        $vendor = Vendor::where('company_id', $companyId)->find($vendorId);
        if (!$vendor) {
            throw new HttpException(422, 'That supplier no longer exists.');
        }
        if ($vendor->status && strcasecmp((string) $vendor->status, 'Active') !== 0) {
            throw new HttpException(422, "{$vendor->name} is " . strtolower((string) $vendor->status) . '. Reactivate the supplier before ordering.');
        }
    }

    private function assertLocation(int $locationId, int $companyId): void
    {
        if (!Location::where('company_id', $companyId)->whereKey($locationId)->exists()) {
            throw new HttpException(422, 'Choose a valid receiving location.');
        }
    }

    private function assertDiscountWithinSubtotal(PurchaseOrder $po): void
    {
        $subtotal = (float) $po->items()->sum('subtotal');
        if ($po->discount_type === 'flat' && (float) $po->discount_value > $subtotal + 0.005) {
            throw new HttpException(422, 'Discount is more than the subtotal.');
        }
    }
}
