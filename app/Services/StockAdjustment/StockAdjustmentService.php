<?php

namespace App\Services\StockAdjustment;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantInventory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Adjusts on-hand stock for a (product/variant, location) and records an
 * `inventory_movements` audit row (type = adjustment). Mirrors the per-location
 * mutation used by StockTransferService, simplified to a single location.
 */
class StockAdjustmentService
{
    /**
     * List past adjustments (inventory_movements of type "adjustment") for a company.
     */
    public function list(int $companyId, array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = InventoryMovement::with(['product:id,name,sku', 'variant:id,name', 'location:id,name'])
            ->where('company_id', $companyId)
            ->where('type', 'adjustment')
            ->orderByDesc('id');

        if (!empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        $paginated = $query->paginate($perPage);

        $data = array_map(function (InventoryMovement $m) {
            return [
                'id'           => $m->id,
                'productId'    => $m->product_id,
                'productName'  => $m->product?->name,
                'variantId'    => $m->variant_id,
                'variantName'  => $m->variant?->name,
                'locationId'   => $m->location_id,
                'locationName' => $m->location?->name,
                'quantity'     => $m->quantity, // signed delta (+/-)
                'notes'        => $m->notes,
                'createdBy'    => $m->created_by,
                'createdAt'    => $m->created_at?->toIso8601String(),
            ];
        }, $paginated->items());

        return [
            'data'         => $data,
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    /**
     * Apply a stock adjustment.
     *
     * @param array{product_id:int,variant_id:?int,location_id:int,type:string,quantity:int,reason:string} $data
     */
    public function create(int $companyId, array $data): array
    {
        $product = Product::where('company_id', $companyId)->find($data['product_id']);
        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        $locationId = (int) $data['location_id'];
        $type       = $data['type'];
        $qty        = (int) $data['quantity'];

        if (($type === 'increase' || $type === 'decrease') && $qty < 1) {
            throw new HttpException(400, 'Quantity must be at least 1 for increase/decrease');
        }

        return DB::transaction(function () use ($companyId, $product, $data, $locationId, $type, $qty) {
            // Resolve the variant: a real one when given, else the product's
            // virtual "Default" variant (same convention as transfers).
            if (!empty($data['variant_id'])) {
                $variant = ProductVariant::find($data['variant_id']);
                if (!$variant || $variant->product_id !== $product->id) {
                    throw new HttpException(404, 'Variant not found or does not belong to this product');
                }
                $isSimple = false;
            } else {
                $variant = ProductVariant::firstOrCreate(
                    ['product_id' => $product->id, 'name' => 'Default'],
                    ['sku' => $product->sku, 'price' => $product->price, 'stock' => 0]
                );
                $isSimple = true;
            }

            // Get or seed the per-location inventory row.
            $inventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $locationId)
                ->first();

            if (!$inventory) {
                // Seed from product.stock if that's where the legacy stock sits.
                $seed = ($product->location_id === $locationId) ? (int) $product->stock : 0;
                $inventory = VariantInventory::create([
                    'variant_id'  => $variant->id,
                    'location_id' => $locationId,
                    'quantity'    => $seed,
                ]);
            }

            $before = (int) $inventory->quantity;

            // Apply the adjustment; `delta` is the signed change we log.
            if ($type === 'increase') {
                $delta = $qty;
            } elseif ($type === 'decrease') {
                if ($before < $qty) {
                    throw new HttpException(400, 'Cannot decrease below zero (current stock: ' . $before . ')');
                }
                $delta = -$qty;
            } else { // set
                $delta = $qty - $before;
            }

            $after = $before + $delta;
            $inventory->quantity = $after;
            $inventory->save();

            // Sync roll-ups: variant.stock and (for simple products) product.stock.
            $totalStock = (int) VariantInventory::where('variant_id', $variant->id)->sum('quantity');
            $variant->stock = $totalStock;
            $variant->save();

            if ($isSimple) {
                $product->stock = $totalStock;
                $withStock = VariantInventory::where('variant_id', $variant->id)
                    ->where('quantity', '>', 0)->pluck('location_id')->toArray();
                if (count($withStock) === 1) {
                    $product->location_id = $withStock[0];
                }
                $product->save();
            }

            // Audit row.
            $movement = InventoryMovement::create([
                'company_id'     => $companyId,
                'product_id'     => $product->id,
                'variant_id'     => $isSimple ? null : $variant->id,
                'location_id'    => $locationId,
                'type'           => 'adjustment',
                'reference_type' => 'manual',
                'quantity'       => $delta,
                'notes'          => $data['reason'],
                // created_by FKs users.id; the JWT actor may be a saas/staff
                // user not present there, so only set it when it resolves.
                'created_by'     => \App\Models\User::whereKey(Auth::id())->exists() ? Auth::id() : null,
            ]);

            return [
                'id'          => $movement->id,
                'productId'   => $product->id,
                'productName' => $product->name,
                'variantId'   => $isSimple ? null : $variant->id,
                'locationId'  => $locationId,
                'type'        => $type,
                'quantity'    => $qty,
                'delta'       => $delta,
                'before'      => $before,
                'after'       => $after,
                'reason'      => $data['reason'],
            ];
        });
    }
}
