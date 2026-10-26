<?php

namespace App\Services\StockAdjustment;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantInventory;
use App\Services\Notification\NotificationService;
use App\Support\ActorName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Adjusts on-hand stock for a (product/variant, location) and records an
 * `inventory_movements` audit row (type = adjustment).
 */
class StockAdjustmentService
{
    private const FLAG_THRESHOLD = 10;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public static function numberFor(int $id): string
    {
        return 'ADJ-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 500);

        $query = InventoryMovement::with(['product:id,name,sku', 'variant:id,name', 'location:id,name', 'createdBy'])
            ->where('company_id', $companyId)
            ->where('type', 'adjustment')
            ->orderByDesc('id');

        if (!empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (!empty($filters['reason'])) {
            $reason = (string) $filters['reason'];
            $query->where(function ($q) use ($reason) {
                $q->where('reason', $reason)
                    ->orWhere(fn ($q2) => $q2->whereNull('reason')->where('notes', $reason));
            });
        }

        $paginated = $query->paginate($perPage);

        $data = array_map(fn (InventoryMovement $m) => $this->format($m), $paginated->items());

        return [
            'data'         => $data,
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'stats'        => $this->stats($companyId, $filters),
            'reasons'      => $this->reasons($companyId),
        ];
    }

    /** Distinct reasons that exist on logged adjustments (legacy rows keep the reason in notes). */
    private function reasons(int $companyId): array
    {
        $base = InventoryMovement::where('company_id', $companyId)->where('type', 'adjustment');
        $new = (clone $base)->whereNotNull('reason')->distinct()->pluck('reason')->all();
        $old = (clone $base)->whereNull('reason')->whereNotNull('notes')->distinct()->pluck('notes')->all();
        $all = array_values(array_unique(array_filter(array_merge($new, $old), fn ($r) => trim((string) $r) !== '')));
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);

        return $all;
    }

    private function format(InventoryMovement $m): array
    {
        $legacy = $m->reason === null; // old rows kept the reason text in `notes`
        $delta  = (int) $m->quantity;
        $unit   = $m->unit_cost !== null ? (float) $m->unit_cost : null;

        if ($m->adjustment_type) {
            $type = $m->adjustment_type;
        } else {
            $type = $delta >= 0 ? 'increase' : 'decrease';
        }

        return [
            'id'           => $m->id,
            'number'       => self::numberFor((int) $m->id),
            'productId'    => $m->product_id,
            'productName'  => $m->product?->name,
            'variantId'    => $m->variant_id,
            'variantName'  => $m->variant?->name,
            'locationId'   => $m->location_id,
            'locationName' => $m->location?->name,
            'type'         => $type,
            'quantity'     => $delta, // signed delta (+/-), kept for backward compat
            'delta'        => $delta,
            'before'       => $m->quantity_before,
            'after'        => $m->quantity_after,
            'reason'       => $legacy ? $m->notes : $m->reason,
            'note'         => $legacy ? null : $m->notes,
            'notes'        => $m->notes ?? $m->reason,
            'flagged'      => (bool) $m->flagged,
            'unitCost'     => $unit,
            'valueImpact'  => $unit !== null ? round($delta * $unit, 2) : null,
            'createdBy'    => $m->created_by_name ?? ($m->createdBy?->username ?? null),
            'createdAt'    => $m->created_at?->toIso8601String(),
        ];
    }

    /** Last-30-day totals (company wide, honouring the location filter if any). */
    private function stats(int $companyId, array $filters): array
    {
        $q = InventoryMovement::where('company_id', $companyId)
            ->where('type', 'adjustment')
            ->where('created_at', '>=', now()->subDays(30));
        if (!empty($filters['location_id'])) {
            $q->where('location_id', (int) $filters['location_id']);
        }

        $added = 0;
        $removed = 0;
        $shrink = 0.0;
        foreach ($q->get(['quantity', 'unit_cost']) as $m) {
            $d = (int) $m->quantity;
            if ($d > 0) {
                $added += $d;
            } elseif ($d < 0) {
                $removed += -$d;
                $shrink += -$d * (float) ($m->unit_cost ?? 0);
            }
        }

        return [
            'added30d'          => $added,
            'removed30d'        => $removed,
            'shrinkageValue30d' => round($shrink, 2),
        ];
    }

    /**
     * Apply a stock adjustment.
     *
     * @param array{product_id:int,variant_id:?int,location_id:int,type:string,quantity:int,reason:string,note?:?string} $data
     */
    public function create(int $companyId, array $data): array
    {
        $product = Product::where('company_id', $companyId)->find($data['product_id']);
        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        $locationId = (int) $data['location_id'];
        if (!\App\Models\Location::where('company_id', $companyId)->whereKey($locationId)->exists()) {
            throw new HttpException(404, 'Location not found');
        }

        $type   = $data['type'];
        $qty    = (int) $data['quantity'];
        $reason = trim((string) $data['reason']);
        $note   = isset($data['note']) ? trim((string) $data['note']) : null;
        $note   = $note === '' ? null : $note;

        if ($reason === '') {
            throw new HttpException(422, 'Reason is required');
        }
        if ($qty < 0) {
            throw new HttpException(422, 'Quantity must not be negative');
        }
        if (($type === 'increase' || $type === 'decrease') && $qty < 1) {
            throw new HttpException(422, 'Quantity must be at least 1 for increase/decrease');
        }

        return DB::transaction(function () use ($companyId, $product, $data, $locationId, $type, $qty, $reason, $note) {
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

            $inventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                // Seed from the legacy stock column when it sits at this location.
                $seed = 0;
                if ($product->location_id === $locationId) {
                    $hasRows = VariantInventory::where('variant_id', $variant->id)->exists();
                    if (!$hasRows) {
                        $seed = $isSimple ? (int) $product->stock : (int) $variant->stock;
                    }
                }
                $inventory = VariantInventory::create([
                    'variant_id'  => $variant->id,
                    'location_id' => $locationId,
                    'quantity'    => $seed,
                ]);
            }

            $before = (int) $inventory->quantity;

            if ($type === 'increase') {
                $delta = $qty;
            } elseif ($type === 'decrease') {
                if ($before < $qty) {
                    throw new HttpException(422, 'Cannot decrease below zero (current stock: ' . $before . ')');
                }
                $delta = -$qty;
            } else { // set
                $delta = $qty - $before;
            }

            $after = $before + $delta;
            $inventory->quantity = $after;
            $inventory->save();

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
            } else {
                $product->stock = (int) ProductVariant::where('product_id', $product->id)
                    ->where('name', '<>', 'Default')->sum('stock');
                $product->save();
            }

            $unitCost = (float) ($variant->cost_price ?? 0);
            if ($isSimple || $unitCost <= 0) {
                $unitCost = (float) ($product->cost_price ?? 0);
            }

            $flagged = abs($delta) >= self::FLAG_THRESHOLD;

            $movement = InventoryMovement::create([
                'company_id'      => $companyId,
                'product_id'      => $product->id,
                'variant_id'      => $isSimple ? null : $variant->id,
                'location_id'     => $locationId,
                'type'            => 'adjustment',
                'adjustment_type' => $type,
                'reference_type'  => 'manual',
                'quantity'        => $delta,
                'quantity_before' => $before,
                'quantity_after'  => $after,
                'unit_cost'       => $unitCost,
                'flagged'         => $flagged,
                'reason'          => $reason,
                'notes'           => $note,
                // created_by FKs users.id; the JWT actor may be a saas/staff user.
                'created_by'      => \App\Models\User::whereKey(Auth::id())->exists() ? Auth::id() : null,
                'created_by_name' => ActorName::current(),
            ]);

            if ($flagged) {
                try {
                    $this->notifications->notifyLargeAdjustment(
                        $companyId,
                        self::numberFor((int) $movement->id),
                        $product->name . (!$isSimple ? ' · ' . $variant->name : ''),
                        (string) \App\Models\Location::whereKey($locationId)->value('name'),
                        $delta,
                        $reason,
                        (int) $movement->id,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Large adjustment notification failed: ' . $e->getMessage());
                }
            }

            return [
                'id'          => $movement->id,
                'number'      => self::numberFor((int) $movement->id),
                'productId'   => $product->id,
                'productName' => $product->name,
                'variantId'   => $isSimple ? null : $variant->id,
                'variantName' => $isSimple ? null : $variant->name,
                'locationId'  => $locationId,
                'type'        => $type,
                'quantity'    => $qty,
                'delta'       => $delta,
                'before'      => $before,
                'after'       => $after,
                'reason'      => $reason,
                'note'        => $note,
                'flagged'     => $flagged,
                'unitCost'    => $unitCost,
                'valueImpact' => round($delta * $unitCost, 2),
                'createdBy'   => $movement->created_by_name,
                'createdAt'   => $movement->created_at?->toIso8601String(),
            ];
        });
    }
}
