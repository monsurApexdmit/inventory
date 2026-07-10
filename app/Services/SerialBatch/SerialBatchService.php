<?php

namespace App\Services\SerialBatch;

use App\DTOs\SerialBatch\BatchDTO;
use App\DTOs\SerialBatch\SerialBatchMapper;
use App\DTOs\SerialBatch\SerialDTO;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SerialBatchService
{
    private readonly SerialBatchMapper $mapper;

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
        $this->mapper = new SerialBatchMapper();
    }

    // ─── Serials ────────────────────────────────────────────────────────────

    public function listSerials(int $companyId, array $filters): array
    {
        $query = ProductSerial::where('company_id', $companyId)
            ->with(['product', 'variant', 'location']);

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (!empty($filters['variant_id'])) {
            $query->where('variant_id', $filters['variant_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $query->where('serial_number', 'like', '%' . $filters['search'] . '%');
        }

        $limit = $filters['limit'] ?? 50;
        $paginated = $query->latest()->paginate($limit);

        return [
            'data'         => array_map(fn($s) => $this->mapper->toSerialDTO($s)->toArray(), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    public function getSerial(int $id, int $companyId): SerialDTO
    {
        $serial = ProductSerial::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['product', 'variant', 'location'])
            ->first();

        if (!$serial) {
            throw new HttpException(404, 'Serial not found');
        }

        return $this->mapper->toSerialDTO($serial);
    }

    public function createSerials(int $companyId, array $data): array
    {
        $productId = $data['productId'];
        $variantId = $data['variantId'] ?? null;
        $locationId = $data['locationId'] ?? null;
        $serials = $data['serials']; // array of { serialNumber, purchaseOrderNumber?, receivedDate?, notes? }

        $this->validateProductTracking($productId, $variantId, 'serial');

        $created = [];
        foreach ($serials as $serialData) {
            $serial = ProductSerial::create([
                'company_id'            => $companyId,
                'product_id'            => $productId,
                'variant_id'            => $variantId,
                'location_id'           => $locationId,
                'serial_number'         => $serialData['serialNumber'],
                'status'                => 'available',
                'purchase_order_number' => $serialData['purchaseOrderNumber'] ?? null,
                'received_date'         => $serialData['receivedDate'] ?? now()->toDateString(),
                'notes'                 => $serialData['notes'] ?? null,
            ]);

            $this->recordMovement($companyId, $productId, $variantId, $locationId, 'receive', 1, serial_id: $serial->id);
            $created[] = $serial;
        }

        // Update product/variant stock
        $this->syncSerialStock($productId, $variantId, $locationId);

        return array_map(
            fn($s) => $this->mapper->toSerialDTO($s->load(['product', 'variant', 'location']))->toArray(),
            $created
        );
    }

    public function updateSerial(int $id, int $companyId, array $data): SerialDTO
    {
        $serial = ProductSerial::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$serial) {
            throw new HttpException(404, 'Serial not found');
        }

        $oldStatus = $serial->status;

        $serial->update(array_filter([
            'location_id'           => $data['locationId'] ?? null,
            'status'                => $data['status'] ?? null,
            'purchase_order_number' => $data['purchaseOrderNumber'] ?? null,
            'received_date'         => $data['receivedDate'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ], fn($v) => $v !== null));

        if (isset($data['status']) && $data['status'] !== $oldStatus) {
            $this->recordMovement($companyId, $serial->product_id, $serial->variant_id, $serial->location_id,
                $data['status'] === 'returned' ? 'return' : 'adjustment', 1, serial_id: $serial->id);
            $this->syncSerialStock($serial->product_id, $serial->variant_id, $serial->location_id);
        }

        return $this->mapper->toSerialDTO($serial->fresh(['product', 'variant', 'location']));
    }

    public function deleteSerial(int $id, int $companyId): void
    {
        $serial = ProductSerial::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$serial) {
            throw new HttpException(404, 'Serial not found');
        }

        if ($serial->status === 'sold') {
            throw new HttpException(400, 'Cannot delete a sold serial number');
        }

        $productId = $serial->product_id;
        $variantId = $serial->variant_id;
        $locationId = $serial->location_id;

        $serial->delete();

        $this->syncSerialStock($productId, $variantId, $locationId);
    }

    // ─── Batches ────────────────────────────────────────────────────────────

    public function listBatches(int $companyId, array $filters): array
    {
        $query = ProductBatch::where('company_id', $companyId)
            ->with(['product', 'variant', 'location']);

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (!empty($filters['variant_id'])) {
            $query->where('variant_id', $filters['variant_id']);
        }
        if (!empty($filters['search'])) {
            $query->where('batch_number', 'like', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['expiring_soon'])) {
            $query->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', now())
                ->where('expiry_date', '<=', now()->addDays(30));
        }
        if (!empty($filters['expired'])) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
        }

        $limit = $filters['limit'] ?? 50;
        $paginated = $query->orderBy('expiry_date')->paginate($limit);

        return [
            'data'         => array_map(fn($b) => $this->mapper->toBatchDTO($b)->toArray(), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    public function getBatch(int $id, int $companyId): BatchDTO
    {
        $batch = ProductBatch::where('id', $id)
            ->where('company_id', $companyId)
            ->with(['product', 'variant', 'location'])
            ->first();

        if (!$batch) {
            throw new HttpException(404, 'Batch not found');
        }

        return $this->mapper->toBatchDTO($batch);
    }

    public function createBatch(int $companyId, array $data): BatchDTO
    {
        $productId = $data['productId'];
        $variantId = $data['variantId'] ?? null;
        $locationId = $data['locationId'] ?? null;

        $this->validateProductTracking($productId, $variantId, 'batch');

        $batch = ProductBatch::create([
            'company_id'            => $companyId,
            'product_id'            => $productId,
            'variant_id'            => $variantId,
            'location_id'           => $locationId,
            'batch_number'          => $data['batchNumber'],
            'quantity_received'     => $data['quantityReceived'],
            'quantity_remaining'    => $data['quantityReceived'],
            'manufacture_date'      => $data['manufactureDate'] ?? null,
            'expiry_date'           => $data['expiryDate'] ?? null,
            'purchase_order_number' => $data['purchaseOrderNumber'] ?? null,
            'received_date'         => $data['receivedDate'] ?? now()->toDateString(),
            'notes'                 => $data['notes'] ?? null,
        ]);

        $this->recordMovement($companyId, $productId, $variantId, $locationId, 'receive', $data['quantityReceived'], batch_id: $batch->id);
        $this->syncBatchStock($productId, $variantId, $locationId);

        // Notify if expiring soon
        if ($batch->isExpiringSoon()) {
            $product = Product::find($productId);
            $this->notificationService->notifyExpiringBatch(
                $companyId,
                $batch->batch_number,
                $product?->name ?? '',
                $batch->expiry_date->toDateString(),
                $batch->quantity_remaining
            );
        }

        return $this->mapper->toBatchDTO($batch->load(['product', 'variant', 'location']));
    }

    public function updateBatch(int $id, int $companyId, array $data): BatchDTO
    {
        $batch = ProductBatch::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$batch) {
            throw new HttpException(404, 'Batch not found');
        }

        $oldQty = $batch->quantity_remaining;

        $updateData = array_filter([
            'location_id'           => $data['locationId'] ?? null,
            'manufacture_date'      => $data['manufactureDate'] ?? null,
            'expiry_date'           => $data['expiryDate'] ?? null,
            'purchase_order_number' => $data['purchaseOrderNumber'] ?? null,
            'received_date'         => $data['receivedDate'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ], fn($v) => $v !== null);

        if (isset($data['quantityRemaining'])) {
            $updateData['quantity_remaining'] = max(0, (int) $data['quantityRemaining']);
        }

        $batch->update($updateData);

        if (isset($data['quantityRemaining']) && $data['quantityRemaining'] != $oldQty) {
            $diff = $data['quantityRemaining'] - $oldQty;
            $this->recordMovement($companyId, $batch->product_id, $batch->variant_id, $batch->location_id,
                'adjustment', $diff, batch_id: $batch->id);
            $this->syncBatchStock($batch->product_id, $batch->variant_id, $batch->location_id);
        }

        return $this->mapper->toBatchDTO($batch->fresh(['product', 'variant', 'location']));
    }

    public function deleteBatch(int $id, int $companyId): void
    {
        $batch = ProductBatch::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$batch) {
            throw new HttpException(404, 'Batch not found');
        }

        $productId = $batch->product_id;
        $variantId = $batch->variant_id;
        $locationId = $batch->location_id;

        $batch->delete();

        $this->syncBatchStock($productId, $variantId, $locationId);
    }

    // ─── Movements ──────────────────────────────────────────────────────────

    public function listMovements(int $companyId, array $filters): array
    {
        $query = InventoryMovement::where('company_id', $companyId)
            ->with(['product', 'variant', 'location', 'serial', 'batch', 'createdBy']);

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $limit = $filters['limit'] ?? 50;
        $paginated = $query->latest()->paginate($limit);

        return [
            'data'         => array_map(fn($m) => $this->formatMovement($m), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    // ─── Stats ──────────────────────────────────────────────────────────────

    public function getStats(int $companyId): array
    {
        $totalSerials   = ProductSerial::where('company_id', $companyId)->count();
        $availableSerials = ProductSerial::where('company_id', $companyId)->where('status', 'available')->count();
        $soldSerials    = ProductSerial::where('company_id', $companyId)->where('status', 'sold')->count();

        $totalBatches    = ProductBatch::where('company_id', $companyId)->count();
        $expiredBatches  = ProductBatch::where('company_id', $companyId)
            ->whereNotNull('expiry_date')->where('expiry_date', '<', now())->count();
        $expiringSoon    = ProductBatch::where('company_id', $companyId)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>=', now())
            ->where('expiry_date', '<=', now()->addDays(30))
            ->count();

        return [
            'serials' => [
                'total'     => $totalSerials,
                'available' => $availableSerials,
                'sold'      => $soldSerials,
                'other'     => $totalSerials - $availableSerials - $soldSerials,
            ],
            'batches' => [
                'total'        => $totalBatches,
                'expired'      => $expiredBatches,
                'expiringSoon' => $expiringSoon,
                'active'       => $totalBatches - $expiredBatches,
            ],
        ];
    }

    // ─── Internal helpers ───────────────────────────────────────────────────

    private function validateProductTracking(int $productId, ?int $variantId, string $expectedType): void
    {
        if ($variantId) {
            $variant = \App\Models\ProductVariant::find($variantId);
            if ($variant && $variant->tracking_type !== 'none' && $variant->tracking_type !== $expectedType) {
                throw new HttpException(400, "Variant is configured for {$variant->tracking_type} tracking, not {$expectedType}");
            }
        } else {
            $product = Product::find($productId);
            if ($product && $product->tracking_type !== 'none' && $product->tracking_type !== $expectedType) {
                throw new HttpException(400, "Product is configured for {$product->tracking_type} tracking, not {$expectedType}");
            }
        }
    }

    private function syncSerialStock(int $productId, ?int $variantId, ?int $locationId): void
    {
        $available = ProductSerial::where('product_id', $productId)
            ->where('status', 'available')
            ->when($variantId, fn($q) => $q->where('variant_id', $variantId))
            ->count();

        if ($variantId) {
            $variant = \App\Models\ProductVariant::find($variantId);
            if ($variant) {
                $variant->update(['stock' => $available]);
                // Sync parent product stock
                $product = Product::find($productId);
                if ($product) {
                    $total = \App\Models\ProductVariant::where('product_id', $productId)->sum('stock');
                    $product->update(['stock' => $total]);
                }
            }
        } else {
            Product::where('id', $productId)->update(['stock' => $available]);
        }
    }

    private function syncBatchStock(int $productId, ?int $variantId, ?int $locationId): void
    {
        $remaining = ProductBatch::where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('variant_id', $variantId))
            ->sum('quantity_remaining');

        if ($variantId) {
            $variant = \App\Models\ProductVariant::find($variantId);
            if ($variant) {
                $variant->update(['stock' => $remaining]);
                $product = Product::find($productId);
                if ($product) {
                    $total = \App\Models\ProductVariant::where('product_id', $productId)->sum('stock');
                    $product->update(['stock' => $total]);
                }
            }
        } else {
            Product::where('id', $productId)->update(['stock' => $remaining]);
        }
    }

    private function recordMovement(
        int $companyId, int $productId, ?int $variantId, ?int $locationId,
        string $type, int $quantity,
        ?string $referenceType = null, ?int $referenceId = null,
        ?int $serial_id = null, ?int $batch_id = null,
        ?string $notes = null
    ): void {
        InventoryMovement::create([
            'company_id'     => $companyId,
            'product_id'     => $productId,
            'variant_id'     => $variantId,
            'location_id'    => $locationId,
            'type'           => $type,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'serial_id'      => $serial_id,
            'batch_id'       => $batch_id,
            'quantity'       => $quantity,
            'notes'          => $notes,
            'created_by'     => Auth::id(),
        ]);
    }

    private function formatMovement(InventoryMovement $m): array
    {
        return [
            'id'            => $m->id,
            'type'          => $m->type,
            'productId'     => $m->product_id,
            'productName'   => $m->product?->name,
            'variantId'     => $m->variant_id,
            'variantName'   => $m->variant?->name,
            'locationId'    => $m->location_id,
            'locationName'  => $m->location?->name,
            'referenceType' => $m->reference_type,
            'referenceId'   => $m->reference_id,
            'serialNumber'  => $m->serial?->serial_number,
            'batchNumber'   => $m->batch?->batch_number,
            'quantity'      => $m->quantity,
            'notes'         => $m->notes,
            'createdBy'     => $m->createdBy?->name,
            'createdAt'     => $m->created_at->toIso8601String(),
        ];
    }
}
