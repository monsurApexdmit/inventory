<?php

namespace App\Services\Product;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\VariantInventory;
use Illuminate\Support\Collection;

/**
 * Computes per-warehouse stock, stock state and bundle availability for a set of products
 * using bulk queries (no per-product lookups).
 *
 * Storage rules (see LocationStockService): real variants and the hidden "Default" variant keep
 * per-location rows in variant_inventory; legacy simple products keep stock on products.stock
 * at products.location_id.
 */
class ProductStockService
{
    /**
     * @param Collection<int, Product> $products models with at least id, stock, reorder_point, is_bundle, location_id
     * @return array<int, array{stock:int, stockByLocation:array, stockState:string, byLoc:array<int,int>}>
     */
    public function compute(Collection $products, int $companyId, ?int $locationId = null): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->all();
        $bundleIds = $products->where('is_bundle', true)->pluck('id')->all();
        $bundleItems = $bundleIds
            ? ProductBundleItem::whereIn('bundle_product_id', $bundleIds)->get()->groupBy('bundle_product_id')
            : collect();

        $childIds = $bundleItems->flatten(1)->pluck('product_id')->unique()->diff($ids)->values()->all();
        $extra = $childIds
            ? Product::whereIn('id', $childIds)->get(['id', 'stock', 'reorder_point', 'is_bundle', 'location_id'])->keyBy('id')
            : collect();
        $byId = $products->keyBy('id')->union($extra);

        $variants = ProductVariant::whereIn('product_id', $byId->keys()->all())->get(['id', 'product_id', 'name', 'stock'])->groupBy('product_id');
        $variantIds = $variants->flatten(1)->pluck('id')->all();
        $inv = [];
        if ($variantIds) {
            foreach (VariantInventory::whereIn('variant_id', $variantIds)->get(['variant_id', 'location_id', 'quantity']) as $row) {
                $inv[$row->variant_id][$row->location_id] = (int) $row->quantity;
            }
        }
        $variantStock = [];
        foreach ($variants->flatten(1) as $v) {
            $variantStock[$v->id] = (int) $v->stock;
        }

        // [productId => [locationId => qty]] and total, for non-bundle products
        $base = [];
        foreach ($byId as $p) {
            $pv = $variants->get($p->id, collect());
            $real = $pv->where('name', '<>', 'Default');
            $default = $pv->firstWhere('name', 'Default');
            $byLoc = [];
            if ($real->isNotEmpty()) {
                foreach ($real as $v) {
                    foreach ($inv[$v->id] ?? [] as $loc => $q) {
                        $byLoc[$loc] = ($byLoc[$loc] ?? 0) + $q;
                    }
                }
            } elseif ($default) {
                $byLoc = $inv[$default->id] ?? [];
            } elseif ($p->location_id) {
                $byLoc[(int) $p->location_id] = (int) $p->stock;
            }
            $base[$p->id] = ['byLoc' => $byLoc, 'total' => (int) $p->stock];
        }

        $locations = Location::where('company_id', $companyId)->orderBy('id')->get(['id', 'name']);

        $out = [];
        foreach ($products as $p) {
            if ($p->is_bundle && $bundleItems->has($p->id)) {
                $byLoc = [];
                foreach ($locations as $loc) {
                    $byLoc[$loc->id] = $this->bundleSlots($bundleItems[$p->id], $base, $inv, $variantStock, $loc->id);
                }
                $total = $this->bundleSlots($bundleItems[$p->id], $base, $inv, $variantStock, null);
            } else {
                $byLoc = $base[$p->id]['byLoc'];
                $total = $base[$p->id]['total'];
            }

            $stock = $locationId !== null ? (int) ($byLoc[$locationId] ?? 0) : $total;
            $reorder = (int) ($p->reorder_point ?? 0);
            $state = $stock <= 0 ? 'out' : ($reorder > 0 && $stock <= $reorder ? 'low' : 'ok');

            $list = [];
            foreach ($locations as $loc) {
                $list[] = ['locationId' => $loc->id, 'locationName' => $loc->name, 'quantity' => (int) ($byLoc[$loc->id] ?? 0)];
            }

            $out[$p->id] = ['stock' => $stock, 'stockByLocation' => $list, 'stockState' => $state, 'byLoc' => $byLoc];
        }

        return $out;
    }

    /** Complete bundles that can be built from child stock (min over children of floor(stock/qty)). */
    private function bundleSlots($items, array $base, array $inv, array $variantStock, ?int $locationId): int
    {
        $min = null;
        foreach ($items as $bi) {
            $qty = max(1, (int) $bi->quantity);
            if ($bi->variant_id) {
                $child = $locationId !== null ? (int) ($inv[$bi->variant_id][$locationId] ?? 0) : (int) ($variantStock[$bi->variant_id] ?? 0);
            } else {
                $b = $base[$bi->product_id] ?? ['byLoc' => [], 'total' => 0];
                $child = $locationId !== null ? (int) ($b['byLoc'][$locationId] ?? 0) : (int) $b['total'];
            }
            $slots = intdiv(max(0, $child), $qty);
            $min = $min === null ? $slots : min($min, $slots);
        }

        return max(0, $min ?? 0);
    }
}
