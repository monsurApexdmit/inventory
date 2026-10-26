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
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('serial_number', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term));
            });
        }

        $limit = $this->perPage($filters);
        $paginated = $query->latest('id')->paginate($limit);

        return [
            'data'         => array_map(fn($s) => $this->mapper->toSerialDTO($s)->toArray(), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    private function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? $filters['limit'] ?? 50), 1), 500);
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

    /**
     * @return array{created: array<int,array>, skipped: array<int,string>}
     */
    public function createSerials(int $companyId, array $data): array
    {
        $productId = $data['productId'];
        $variantId = $data['variantId'] ?? null;
        $locationId = $data['locationId'] ?? null;
        $defaultStatus = $data['status'] ?? 'available';
        $serials = $data['serials']; // array of { serialNumber, status?, purchaseOrderNumber?, receivedDate?, notes? }

        $this->validateProductTracking($productId, $variantId, 'serial');

        $created = [];
        $skipped = [];
        $seen = [];
        foreach ($serials as $serialData) {
            $number = trim((string) $serialData['serialNumber']);
            $key = mb_strtolower($number);
            if ($number === '' || isset($seen[$key])
                || ProductSerial::withTrashed()->where('company_id', $companyId)->where('serial_number', $number)->exists()) {
                $skipped[] = $number;
                continue;
            }
            $seen[$key] = true;

            $status = $serialData['status'] ?? $defaultStatus;
            $serial = ProductSerial::create([
                'company_id'            => $companyId,
                'product_id'            => $productId,
                'variant_id'            => $variantId,
                'location_id'           => $locationId,
                'serial_number'         => $number,
                'status'                => $status,
                'purchase_order_number' => $serialData['purchaseOrderNumber'] ?? null,
                'received_date'         => $serialData['receivedDate'] ?? now()->toDateString(),
                'notes'                 => $serialData['notes'] ?? null,
            ]);

            $this->recordMovement($companyId, $productId, $variantId, $locationId, 'receive', 1, serial_id: $serial->id);
            $created[] = $serial;
        }

        if ($created) {
            $this->syncSerialStock($productId, $variantId, $locationId);
        }

        return [
            'created' => array_map(
                fn($s) => $this->mapper->toSerialDTO($s->load(['product', 'variant', 'location']))->toArray(),
                $created
            ),
            'skipped' => $skipped,
        ];
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
            $term = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('batch_number', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term));
            });
        }

        $today = now()->toDateString();
        $soonEnd = now()->addDays(30)->toDateString();
        $status = $filters['status'] ?? null;
        if ($status === 'expired' || !empty($filters['expired'])) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '<=', $today);
        } elseif ($status === 'soon' || !empty($filters['expiring_soon'])) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '>', $today)->where('expiry_date', '<=', $soonEnd);
        } elseif ($status === 'ok') {
            $query->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', $soonEnd));
        }

        $limit = $this->perPage($filters);
        $paginated = $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')->paginate($limit);

        $ranks = $this->fefoRanks($companyId, array_unique(array_map(fn ($b) => $b->product_id, $paginated->items())));

        return [
            'data'         => array_map(function ($b) use ($ranks) {
                $rank = $ranks[$b->id] ?? null;
                if ($b->isExpired()) {
                    $label = 'Expired — do not sell';
                } elseif ((int) $b->quantity_remaining <= 0) {
                    $label = 'Depleted';
                } else {
                    $label = $rank === 1 ? 'Sell first' : ($rank ? 'Next' : null);
                }

                return $this->mapper->toBatchDTO($b)->toArray()
                    + ['sellOrder' => $rank, 'sellOrderLabel' => $label];
            }, $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    /** batch_id => FEFO rank among non-empty, non-expired batches of the same product. */
    private function fefoRanks(int $companyId, array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $batches = ProductBatch::where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->where('quantity_remaining', '>', 0)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now()->toDateString()))
            ->get(['id', 'product_id', 'variant_id', 'expiry_date', 'received_date'])
            ->sort(function ($a, $b) {
                $ea = $a->expiry_date?->timestamp ?? PHP_INT_MAX;
                $eb = $b->expiry_date?->timestamp ?? PHP_INT_MAX;
                if ($ea !== $eb) {
                    return $ea <=> $eb;
                }
                $ra = $a->received_date?->timestamp ?? PHP_INT_MAX;
                $rb = $b->received_date?->timestamp ?? PHP_INT_MAX;
                return $ra !== $rb ? $ra <=> $rb : $a->id <=> $b->id;
            });

        $counters = [];
        $ranks = [];
        foreach ($batches as $b) {
            $k = (string) $b->product_id;
            $counters[$k] = ($counters[$k] ?? 0) + 1;
            $ranks[$b->id] = $counters[$k];
        }
        return $ranks;
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
        $this->assertBatchNumberFree($companyId, $productId, $data['batchNumber']);

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
        $oldProductId = $batch->product_id;
        $oldVariantId = $batch->variant_id;
        $oldLocationId = $batch->location_id;

        $productId = isset($data['productId']) ? (int) $data['productId'] : (int) $batch->product_id;
        $variantId = array_key_exists('variantId', $data) ? ($data['variantId'] ?: null) : $batch->variant_id;
        $productChanged = $productId !== (int) $batch->product_id || (int) ($variantId ?? 0) !== (int) ($batch->variant_id ?? 0);

        if ($productChanged) {
            if (!Product::where('company_id', $companyId)->whereKey($productId)->exists()) {
                throw new HttpException(404, 'Product not found');
            }
            if ($variantId && !\App\Models\ProductVariant::where('product_id', $productId)->whereKey($variantId)->exists()) {
                throw new HttpException(404, 'Variant not found for this product');
            }
            $this->validateProductTracking($productId, $variantId, 'batch');
        }

        $number = !empty($data['batchNumber']) ? $data['batchNumber'] : $batch->batch_number;
        if ($number !== $batch->batch_number || $productChanged) {
            $this->assertBatchNumberFree($companyId, $productId, $number, $batch->id);
        }

        $updateData = array_filter([
            'batch_number' => $data['batchNumber'] ?? null,
            'location_id'  => $data['locationId'] ?? null,
            'received_date' => $data['receivedDate'] ?? null,
        ], fn($v) => $v !== null);

        // Optional fields can be cleared by sending null/empty.
        $map = ['manufactureDate' => 'manufacture_date', 'expiryDate' => 'expiry_date', 'purchaseOrderNumber' => 'purchase_order_number', 'notes' => 'notes'];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $updateData[$col] = ($data[$in] === '' ? null : $data[$in]);
            }
        }

        if ($productChanged) {
            $updateData['product_id'] = $productId;
            $updateData['variant_id'] = $variantId;
        }

        if (isset($data['quantityRemaining'])) {
            $newQty = max(0, (int) $data['quantityRemaining']);
            $updateData['quantity_remaining'] = $newQty;
            if ($newQty > (int) $batch->quantity_received) {
                $updateData['quantity_received'] = $newQty;
            }
        }

        $batch->update($updateData);

        if (isset($data['quantityRemaining']) && (int) $data['quantityRemaining'] !== (int) $oldQty) {
            $diff = (int) $data['quantityRemaining'] - (int) $oldQty;
            $this->recordMovement($companyId, $batch->product_id, $batch->variant_id, $batch->location_id,
                'adjustment', $diff, batch_id: $batch->id);
        }

        if ($productChanged) {
            $this->syncBatchStock($oldProductId, $oldVariantId, $oldLocationId);
        }
        $this->syncBatchStock($batch->product_id, $batch->variant_id, $batch->location_id);

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

        $today = now()->toDateString();
        $soonEnd = now()->addDays(30)->toDateString();

        $totalBatches    = ProductBatch::where('company_id', $companyId)->count();
        $expiredQ  = fn () => ProductBatch::where('company_id', $companyId)
            ->whereNotNull('expiry_date')->where('expiry_date', '<=', $today);
        $soonQ    = fn () => ProductBatch::where('company_id', $companyId)
            ->whereNotNull('expiry_date')->where('expiry_date', '>', $today)->where('expiry_date', '<=', $soonEnd);

        $expiredBatches = $expiredQ()->count();
        $expiringSoon   = $soonQ()->count();
        $firstSoon      = $soonQ()->orderBy('expiry_date')->orderBy('id')->first();
        $firstExpired   = $expiredQ()->orderBy('expiry_date')->orderBy('id')->first();

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
            'batchCount'        => $totalBatches,
            'batchProducts'     => ProductBatch::where('company_id', $companyId)->distinct()->count('product_id'),
            'expiringSoonCount' => $expiringSoon,
            'firstExpiring'     => $firstSoon ? ['batchNumber' => $firstSoon->batch_number, 'quantity' => (int) $firstSoon->quantity_remaining] : null,
            'expiredCount'      => $expiredBatches,
            'firstExpired'      => $firstExpired ? ['batchNumber' => $firstExpired->batch_number] : null,
            'serialCount'       => $totalSerials,
            'serialAvailable'   => $availableSerials,
            'serialSold'        => $soldSerials,
        ];
    }

    // ─── Internal helpers ───────────────────────────────────────────────────

    private function assertBatchNumberFree(int $companyId, int $productId, string $batchNumber, ?int $ignoreId = null): void
    {
        // Includes soft-deleted rows: they still occupy the (company, batch_number) unique index.
        $q = ProductBatch::withTrashed()->where('company_id', $companyId)->where('batch_number', $batchNumber);
        if ($ignoreId) {
            $q->where('id', '<>', $ignoreId);
        }
        $existing = $q->first();
        if ($existing) {
            $same = (int) $existing->product_id === $productId;
            throw new HttpException(422, $same
                ? "Batch number {$batchNumber} already exists for this product"
                : "Batch number {$batchNumber} is already in use");
        }
    }

    private function validateProductTracking(int $productId, ?int $variantId, string $expectedType): void
    {
        $product = Product::find($productId);
        $productType = $product?->tracking_type ?: 'none';

        if ($variantId) {
            $variant = \App\Models\ProductVariant::find($variantId);
            $variantType = $variant?->tracking_type ?: 'none';
            if ($variantType !== 'none' && $variantType !== $expectedType) {
                throw new HttpException(400, "Variant is configured for {$variantType} tracking, not {$expectedType}");
            }
            // A variant set to 'none' follows its product's tracking type.
            if ($variantType === 'none' && $productType !== $expectedType) {
                throw new HttpException(422, "Turn on {$expectedType} tracking for this product before adding {$expectedType} records — otherwise its stock total would be overwritten.");
            }
        } else {
            if ($productType !== 'none' && $productType !== $expectedType) {
                throw new HttpException(400, "Product is configured for {$productType} tracking, not {$expectedType}");
            }
            // Untracked product: batch/serial records would overwrite products.stock.
            if ($productType === 'none') {
                throw new HttpException(422, "Turn on {$expectedType} tracking for this product before adding {$expectedType} records — otherwise its stock total would be overwritten.");
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
            // created_by FKs users.id; the JWT actor may be a saas/staff user.
            'created_by'     => \App\Models\User::whereKey(Auth::id())->exists() ? Auth::id() : null,
            'created_by_name' => \App\Support\ActorName::current(),
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
            'createdBy'     => $m->created_by_name ?? $m->createdBy?->username,
            'createdAt'     => $m->created_at->toIso8601String(),
        ];
    }
}
