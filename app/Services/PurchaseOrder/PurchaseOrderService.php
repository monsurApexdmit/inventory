<?php

namespace App\Services\PurchaseOrder;

use App\DTOs\PurchaseOrder\PurchaseOrderDTO;
use App\DTOs\PurchaseOrder\PurchaseOrderMapper;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\VariantInventory;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderService
{
    private readonly PurchaseOrderMapper $mapper;

    public function __construct(private readonly IPurchaseOrderRepository $repository)
    {
        $this->mapper = new PurchaseOrderMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);

        return [
            'data'         => array_map(fn($po) => $this->mapper->toDTO($po)->toArray(), $paginated->items()),
            'total'        => $paginated->total(),
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
        return DB::transaction(function () use ($companyId, $data) {
            $poNumber = $this->repository->nextPoNumber($companyId);

            $po = $this->repository->create([
                'company_id'    => $companyId,
                'vendor_id'     => $data['vendorId'],
                'location_id'   => $data['locationId'] ?? null,
                'po_number'     => $poNumber,
                'status'        => 'draft',
                'expected_date' => $data['expectedDate'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'total_amount'  => 0,
            ]);

            $total = 0;
            foreach ($data['items'] as $item) {
                $subtotal = ($item['unitCost'] ?? 0) * ($item['quantityOrdered'] ?? 0);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $item['productId'],
                    'variant_id'        => $item['variantId'] ?? null,
                    'quantity_ordered'  => $item['quantityOrdered'],
                    'quantity_received' => 0,
                    'unit_cost'         => $item['unitCost'] ?? 0,
                    'subtotal'          => $subtotal,
                ]);
                $total += $subtotal;
            }

            $po->update(['total_amount' => $total]);

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

        return DB::transaction(function () use ($po, $companyId, $data) {
            $po->update([
                'vendor_id'     => $data['vendorId'] ?? $po->vendor_id,
                'location_id'   => $data['locationId'] ?? $po->location_id,
                'status'        => $data['status'] ?? $po->status,
                'expected_date' => $data['expectedDate'] ?? $po->expected_date,
                'notes'         => $data['notes'] ?? $po->notes,
            ]);

            if (isset($data['items'])) {
                $po->items()->delete();
                $total = 0;
                foreach ($data['items'] as $item) {
                    $subtotal = ($item['unitCost'] ?? 0) * ($item['quantityOrdered'] ?? 0);
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'product_id'        => $item['productId'],
                        'variant_id'        => $item['variantId'] ?? null,
                        'quantity_ordered'  => $item['quantityOrdered'],
                        'quantity_received' => $item['quantityReceived'] ?? 0,
                        'unit_cost'         => $item['unitCost'] ?? 0,
                        'subtotal'          => $subtotal,
                    ]);
                    $total += $subtotal;
                }
                $po->update(['total_amount' => $total]);
            }

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

        return DB::transaction(function () use ($po, $companyId, $data) {
            foreach ($data['items'] as $received) {
                $item = $po->items()->where('id', $received['itemId'])->first();
                if (!$item) {
                    continue;
                }

                $qtyReceiving = (int) $received['quantityReceiving'];
                if ($qtyReceiving <= 0) {
                    continue;
                }

                $remaining = $item->quantity_ordered - $item->quantity_received;
                $qtyReceiving = min($qtyReceiving, $remaining);

                // Update stock
                if ($item->variant_id) {
                    $this->addVariantStock($item->variant_id, $po->location_id, $qtyReceiving);
                } else {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        $product->increment('stock', $qtyReceiving);
                    }
                }

                $item->increment('quantity_received', $qtyReceiving);
            }

            // Recalculate status
            $po->load('items');
            $allReceived = $po->items->every(fn($i) => $i->quantity_received >= $i->quantity_ordered);
            $anyReceived = $po->items->some(fn($i) => $i->quantity_received > 0);

            $newStatus = $allReceived ? 'received' : ($anyReceived ? 'partial' : $po->status);
            $po->update(['status' => $newStatus]);

            return $this->mapper->toDTO(
                $this->repository->findById($po->id, $companyId)
            );
        });
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

        $po->update(['status' => $status]);

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

        if (in_array($po->status, ['partial', 'received'])) {
            throw new HttpException(422, 'Cannot delete a purchase order that has received stock');
        }

        $this->repository->delete($id, $companyId);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function addVariantStock(int $variantId, ?int $locationId, int $qty): void
    {
        if ($locationId) {
            $inv = VariantInventory::firstOrCreate(
                ['variant_id' => $variantId, 'location_id' => $locationId],
                ['quantity' => 0]
            );
            $inv->increment('quantity', $qty);
        } else {
            $inv = VariantInventory::where('variant_id', $variantId)
                ->orderByDesc('quantity')
                ->first();
            if ($inv) {
                $inv->increment('quantity', $qty);
            } else {
                VariantInventory::create(['variant_id' => $variantId, 'location_id' => null, 'quantity' => $qty]);
            }
        }

        // Sync variant.stock
        $variant = ProductVariant::find($variantId);
        if ($variant) {
            $variant->stock = VariantInventory::where('variant_id', $variantId)->sum('quantity');
            $variant->save();

            // Sync product.stock
            $product = $variant->product;
            if ($product) {
                $product->stock = ProductVariant::where('product_id', $product->id)->sum('stock');
                $product->save();
            }
        }
    }
}
