<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * SKU-level stock overview: per-location quantities, reserved, availability and health.
 *
 * Stock storage: real variants and the virtual "Default" variant of simple products keep
 * per-location rows in `variant_inventory`; legacy stock lives in products.stock /
 * product_variants.stock at products.location_id.
 */
class InventoryOverviewService
{
    public function overview(int $companyId, array $f): array
    {
        $search     = trim((string) ($f['search'] ?? ''));
        $locationId = !empty($f['location_id']) ? (int) $f['location_id'] : null;
        $categoryId = !empty($f['category_id']) ? (int) $f['category_id'] : null;
        $health     = in_array($f['health'] ?? 'all', ['all', 'attention', 'ok', 'low', 'out', 'over'], true) ? ($f['health'] ?? 'all') : 'all';
        $page       = max(1, (int) ($f['page'] ?? 1));
        $perPage    = min(max((int) ($f['per_page'] ?? 500), 1), 1000);

        $locations = DB::table('locations')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'name', 'is_default']);
        $categoriesAll = DB::table('categories')->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderBy('category_name')->get(['id', 'category_name']);
        $catName = $categoriesAll->pluck('category_name', 'id');

        $pq = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at');
        if ($categoryId) {
            $pq->where('category_id', $categoryId);
        }
        $products = $pq->orderBy('name')->get();
        $productIds = $products->pluck('id')->all();

        // Variants grouped by product.
        $variantsByProduct = [];
        $allVariantIds = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            foreach (DB::table('product_variants')->whereIn('product_id', $chunk)->whereNull('deleted_at')->orderBy('id')->get() as $v) {
                $variantsByProduct[$v->product_id][] = $v;
                $allVariantIds[] = $v->id;
            }
        }

        // variant_id => [location_id => qty]
        $inv = [];
        foreach (array_chunk($allVariantIds, 1000) as $chunk) {
            foreach (DB::table('variant_inventory')->whereIn('variant_id', $chunk)->get(['variant_id', 'location_id', 'quantity']) as $r) {
                $inv[$r->variant_id][$r->location_id] = ($inv[$r->variant_id][$r->location_id] ?? 0) + (int) $r->quantity;
            }
        }

        $reservedRaw = $this->reservedMap($companyId);

        $locIds = $locations->pluck('id')->all();
        $locName = $locations->pluck('name', 'id');

        $rows = [];
        foreach ($products as $p) {
            $all = $variantsByProduct[$p->id] ?? [];
            $real = array_values(array_filter($all, fn ($v) => $v->name !== 'Default'));
            $default = null;
            foreach ($all as $v) {
                if ($v->name === 'Default') {
                    $default = $v;
                    break;
                }
            }

            $productCost = (float) $p->cost_price;
            $base = [
                'productId'    => (int) $p->id,
                'name'         => $p->name,
                'categoryId'   => $p->category_id ? (int) $p->category_id : null,
                'categoryName' => $p->category_id ? ($catName[$p->category_id] ?? null) : null,
                'published'    => (bool) $p->published,
            ];

            if (count($real) === 0) {
                $perLoc = $this->perLocation($default ? ($inv[$default->id] ?? []) : [], (int) $p->stock, $p->location_id);
                $res = ($reservedRaw['p'][$p->id] ?? 0) + ($default ? ($reservedRaw['v'][$default->id] ?? 0) : 0);
                $row = $base + [
                    'type' => 'product', 'id' => (int) $p->id, 'variantName' => null,
                    'sku' => $p->sku, 'barcode' => $p->barcode,
                    'reorderPoint' => (int) $p->reorder_point, 'cost' => $productCost,
                ];
                $rows[] = $this->finish($row, $perLoc, $locIds, $locName, $locationId, $res, null);
                continue;
            }

            $children = [];
            foreach ($real as $v) {
                $perLoc = $this->perLocation($inv[$v->id] ?? [], (int) $v->stock, $p->location_id);
                $cost = (float) $v->cost_price > 0 ? (float) $v->cost_price : $productCost;
                $child = $base + [
                    'type' => 'variant', 'id' => (int) $v->id, 'variantName' => $v->name,
                    'sku' => $v->sku, 'barcode' => $v->barcode,
                    'reorderPoint' => (int) $v->reorder_point, 'cost' => $cost,
                ];
                $children[] = $this->finish($child, $perLoc, $locIds, $locName, $locationId, $reservedRaw['v'][$v->id] ?? 0, null);
            }

            // Parent = aggregate of variants.
            $locTotals = array_fill_keys($locIds, 0);
            foreach ($children as $c) {
                foreach ($c['locations'] as $l) {
                    $locTotals[$l['locationId']] += $l['quantity'];
                }
            }
            $parentRop = (int) $p->reorder_point > 0 ? (int) $p->reorder_point : array_sum(array_column($children, 'reorderPoint'));
            $parent = $base + [
                'type' => 'product', 'id' => (int) $p->id, 'variantName' => null,
                'sku' => $p->sku, 'barcode' => $p->barcode,
                'reorderPoint' => $parentRop, 'cost' => $productCost,
            ];
            $parent = $this->finish($parent, null, $locIds, $locName, $locationId, 0, $children, $locTotals);
            $rows[] = $parent;
        }

        // Search (product name/sku/barcode or any variant name/sku/barcode).
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $hit = fn ($v) => $v !== null && str_contains(mb_strtolower((string) $v), $needle);
            $rows = array_values(array_filter($rows, function ($r) use ($hit) {
                if ($hit($r['name']) || $hit($r['sku']) || $hit($r['barcode'])) {
                    return true;
                }
                foreach ($r['variants'] ?? [] as $v) {
                    if ($hit($v['variantName']) || $hit($v['sku']) || $hit($v['barcode'])) {
                        return true;
                    }
                }
                return false;
            }));
        }

        // Tab counts + stats ignore the health filter.
        $counts = ['all' => count($rows), 'attention' => 0, 'ok' => 0, 'low' => 0, 'out' => 0, 'over' => 0];
        $stats = ['skus' => count($rows), 'categories' => 0, 'units' => 0, 'reserved' => 0, 'stockValue' => 0.0,
            'published' => 0, 'unpublished' => 0, 'low' => 0, 'out' => 0, 'over' => 0, 'ok' => 0, 'attention' => 0];
        $cats = [];
        foreach ($rows as $r) {
            $counts[$r['health']]++;
            if ($r['health'] === 'low' || $r['health'] === 'out') {
                $counts['attention']++;
            }
            $stats['units'] += $r['onHand'];
            $stats['reserved'] += $r['reserved'];
            $stats['stockValue'] += $r['value'];
            $r['published'] ? $stats['published']++ : $stats['unpublished']++;
            if ($r['categoryId']) {
                $cats[$r['categoryId']] = true;
            }
        }
        $stats['categories'] = count($cats);
        $stats['stockValue'] = round($stats['stockValue'], 2);
        foreach (['low', 'out', 'over', 'ok', 'attention'] as $k) {
            $stats[$k] = $counts[$k];
        }

        if ($health !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $health === 'attention'
                ? in_array($r['health'], ['low', 'out'], true) : $r['health'] === $health));
        }

        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'lastPage'   => max(1, (int) ceil($total / $perPage)),
            'stats'      => $stats,
            'counts'     => $counts,
            'locations'  => $locations->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'isDefault' => (bool) $l->is_default])->values()->all(),
            'categories' => $categoriesAll->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->category_name])->values()->all(),
        ];
    }

    /** location_id => qty, falling back to legacy stock at the product's location when no rows exist. */
    private function perLocation(array $rows, int $legacyStock, $productLocationId): array
    {
        if (!empty($rows)) {
            return $rows;
        }
        return ($legacyStock > 0 && $productLocationId) ? [(int) $productLocationId => $legacyStock] : [];
    }

    private function finish(array $row, ?array $perLoc, array $locIds, $locName, ?int $filterLoc, int $reserved, ?array $children, ?array $locTotals = null): array
    {
        $locs = [];
        foreach ($locIds as $id) {
            $q = $locTotals !== null ? (int) $locTotals[$id] : (int) ($perLoc[$id] ?? 0);
            $locs[] = ['locationId' => (int) $id, 'locationName' => $locName[$id], 'quantity' => $q];
        }
        $onHand = 0;
        foreach ($locs as $l) {
            if ($filterLoc === null || $l['locationId'] === $filterLoc) {
                $onHand += $l['quantity'];
            }
        }

        if ($children !== null) {
            $row['variants'] = $children;
            $reserved = array_sum(array_column($children, 'reserved'));
            $value = array_sum(array_column($children, 'value'));
        } else {
            $reserved = min($reserved, $onHand);
            $value = $onHand * $row['cost'];
        }

        $rop = $row['reorderPoint'];
        $health = $onHand === 0 ? 'out'
            : ($onHand <= $rop ? 'low'
            : (($rop > 0 && $onHand > $rop * 4) ? 'over' : 'ok'));

        $row['locations'] = $locs;
        $row['onHand'] = $onHand;
        $row['reserved'] = $reserved;
        $row['available'] = max(0, $onHand - $reserved);
        $row['health'] = $health;
        $row['value'] = round($value, 2);
        if ($children === null && $row['type'] === 'product') {
            $row['variants'] = [];
        }
        return $row;
    }

    /** Units on open orders: ['p' => product_id => qty (no variant), 'v' => variant_id => qty]. */
    private function reservedMap(int $companyId): array
    {
        $rows = DB::table('order_items as oi')
            ->join('sells as s', 's.id', '=', 'oi.sell_id')
            ->where('s.company_id', $companyId)
            ->whereNull('s.deleted_at')
            ->whereNotIn('s.status', ['Cancelled', 'Delivered'])
            ->where(function ($q) {
                $q->whereNull('s.fulfillment_status')->orWhere('s.fulfillment_status', '<>', 'shipped');
            })
            ->groupBy('oi.product_id', 'oi.variant_id')
            ->get(['oi.product_id', 'oi.variant_id', DB::raw('SUM(oi.quantity) as qty')]);

        $map = ['p' => [], 'v' => []];
        foreach ($rows as $r) {
            if ($r->variant_id) {
                $map['v'][$r->variant_id] = ($map['v'][$r->variant_id] ?? 0) + (int) $r->qty;
            } else {
                $map['p'][$r->product_id] = ($map['p'][$r->product_id] ?? 0) + (int) $r->qty;
            }
        }
        return $map;
    }
}
