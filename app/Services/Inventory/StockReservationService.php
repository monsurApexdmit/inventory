<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\Sell;
use App\Models\VariantInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe "check then take" for order stock, built on LocationStockService (same storage rules).
 *
 * Callers run everything inside ONE database transaction:
 *   1. lockAndValidate(): row-lock the products (plus bundle components), their variants and the
 *      variant_inventory rows at the draw locations, each in ascending id order, then compare the locked
 *      quantities with what the order needs. A shortage on any line rejects the whole order.
 *   2. deduct(): take the stock. LocationStockService::change() re-checks each row under its lock and
 *      refuses to go below zero, so a missed case can only fail the transaction, never oversell.
 *   3. commit/rollback releases the locks; the next waiting checkout then reads the reduced stock.
 *
 * Quantities are read with locking reads (SELECT ... FOR UPDATE), which always return the latest committed
 * values, so the result does not depend on when the transaction's MVCC snapshot was taken.
 *
 * Lock order is products -> variants -> inventory rows, ascending id within each. Every other stock writer
 * (LocationStockService::change, order cancellation) locks the product row first too, so stock changes for a
 * product are serialised and two carts holding the same products in a different order cannot deadlock.
 */
class StockReservationService
{
    public function __construct(private readonly LocationStockService $locStock)
    {
    }

    /**
     * Merge cart items into one line per product/variant (repeated lines add up), ordered by product then variant.
     *
     * @param array<int, array{product_id:int|string, variant_id?:int|string|null, quantity:int|string}> $items
     * @return list<array{product_id:int, variant_id:?int, quantity:int}>
     */
    public static function mergeLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $vid = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
            $key = sprintf('%012d|%012d', $pid, $vid ?? 0);
            $lines[$key] ??= ['product_id' => $pid, 'variant_id' => $vid, 'quantity' => 0];
            $lines[$key]['quantity'] += (int) $item['quantity'];
        }
        ksort($lines);

        return array_values($lines);
    }

    /**
     * Lock the stock rows of $lines and make sure every line is covered.
     *
     * @param list<array{product_id:int, variant_id:?int, quantity:int}> $lines
     * @param array<int>|null $locationIds Locations the order draws from (per-location stock); null = legacy flat stock.
     *
     * @throws InsufficientStockException
     */
    public function lockAndValidate(int $companyId, array $lines, ?array $locationIds): void
    {
        $this->assertInTransaction();
        $state = $this->lockState($companyId, $lines, $locationIds);

        $shortages = [];
        foreach ($lines as $line) {
            $have = $this->available($state, $line['product_id'], $line['variant_id'], $locationIds);
            if ($have < $line['quantity']) {
                $shortages[] = $this->shortage($state, $line, $have);
            }
        }
        if ($shortages) {
            throw new InsufficientStockException($shortages);
        }
    }

    /**
     * Take the stock for an order whose lines were locked by lockAndValidate() in this transaction.
     * Per-location stock comes from the fullest location first (one location when it covers the line) and is
     * logged as `sell` inventory movements referencing the order, so cancellation returns it to the same place.
     * Legacy flat stock is decremented in place as before.
     *
     * @param list<array{product_id:int, variant_id:?int, quantity:int}> $lines
     * @return array<int,int> [locationId => units taken] (empty for legacy flat stock)
     *
     * @throws InsufficientStockException
     */
    public function deduct(int $companyId, Sell $sell, array $lines, ?array $locationIds, string $reason = 'Online order'): array
    {
        $this->assertInTransaction();
        $meta = ['reference_type' => 'sell', 'reference_id' => $sell->id, 'reason' => $reason, 'notes' => $sell->invoice_no];

        $byLocation = [];
        foreach ($lines as $line) {
            // Re-read the locked rows: an earlier line (e.g. a bundle sharing a component) may have changed them
            $state = $this->lockState($companyId, $lines, $locationIds);
            $product = $state['products']->get($line['product_id']);
            if (!$product) {
                throw new InsufficientStockException([$this->shortage($state, $line, 0)]);
            }

            if ($locationIds === null) {
                if ($this->available($state, $line['product_id'], $line['variant_id'], null) < $line['quantity']) {
                    throw new InsufficientStockException([$this->shortage($state, $line, $this->available($state, $line['product_id'], $line['variant_id'], null))]);
                }
                $this->deductFlat($companyId, $line);
                continue;
            }

            $perLocation = [];
            foreach ($locationIds as $loc) {
                $perLocation[(int) $loc] = $this->availableAt($state, $line['product_id'], $line['variant_id'], (int) $loc);
            }
            arsort($perLocation);
            if (array_sum($perLocation) < $line['quantity']) {
                throw new InsufficientStockException([$this->shortage($state, $line, array_sum($perLocation))]);
            }
            $need = $line['quantity'];
            foreach ($perLocation as $loc => $have) {
                $take = min($need, $have);
                if ($take <= 0) {
                    continue;
                }
                $this->locStock->deduct($companyId, $product, $line['variant_id'], $loc, $take, $meta);
                $byLocation[$loc] = ($byLocation[$loc] ?? 0) + $take;
                $need -= $take;
                if ($need <= 0) {
                    break;
                }
            }
        }

        return $byLocation;
    }

    /**
     * Row-lock products and their bundle components, ascending id.
     *
     * @param array<int> $productIds
     * @return Collection<int, Product> keyed by id
     */
    public function lockProducts(int $companyId, array $productIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if (!$ids) {
            return collect();
        }
        $componentIds = ProductBundleItem::whereIn('bundle_product_id', $ids)->sharedLock()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $all = array_values(array_unique(array_merge($ids, $componentIds)));
        sort($all);

        return Product::where('company_id', $companyId)
            ->whereIn('id', $all)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Lock and read everything availability depends on (all locking reads, so values are current).
     *
     * @return array{products: Collection, variants: Collection, bundles: Collection, inventory: array<string,int>}
     */
    private function lockState(int $companyId, array $lines, ?array $locationIds): array
    {
        $products = $this->lockProducts($companyId, array_column($lines, 'product_id'));
        $productIds = $products->keys()->all();

        $variants = $productIds
            ? ProductVariant::whereIn('product_id', $productIds)->orderBy('id')->lockForUpdate()->get(['id', 'product_id', 'name', 'stock'])
            : collect();
        $bundles = $productIds
            ? ProductBundleItem::whereIn('bundle_product_id', $productIds)->sharedLock()->get(['bundle_product_id', 'product_id', 'variant_id', 'quantity'])->groupBy('bundle_product_id')
            : collect();

        $inventory = [];
        if ($locationIds !== null && $locationIds && $variants->isNotEmpty()) {
            VariantInventory::whereIn('variant_id', $variants->pluck('id'))
                ->whereIn('location_id', $locationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['variant_id', 'location_id', 'quantity'])
                ->each(function (VariantInventory $row) use (&$inventory) {
                    $inventory[$row->variant_id . '|' . $row->location_id] = (int) $row->quantity;
                });
        }

        return ['products' => $products, 'variants' => $variants, 'bundles' => $bundles, 'inventory' => $inventory];
    }

    /** Units available for a line: across the draw locations, or flat stock when $locationIds is null. */
    private function available(array $state, int $productId, ?int $variantId, ?array $locationIds): int
    {
        if ($locationIds === null) {
            $product = $state['products']->get($productId);
            if (!$product) {
                return 0;
            }
            if ($variantId) {
                $variant = $state['variants']->first(fn ($v) => (int) $v->id === $variantId && (int) $v->product_id === $productId);

                return (int) ($variant?->stock ?? 0);
            }

            return (int) $product->stock;
        }

        // Per location, then summed: an order line (and a bundle's components) is taken location by location.
        $total = 0;
        foreach ($locationIds as $loc) {
            $total += $this->availableAt($state, $productId, $variantId, (int) $loc);
        }

        return $total;
    }

    /** Same storage rules as LocationStockService::availableAcross(), for one location, from locked rows. */
    private function availableAt(array $state, int $productId, ?int $variantId, int $locationId): int
    {
        $product = $state['products']->get($productId);
        if (!$product) {
            return 0;
        }
        if ($product->is_bundle) {
            $slots = null;
            foreach ($state['bundles']->get($productId, collect()) as $bi) {
                $n = intdiv($this->availableAt($state, (int) $bi->product_id, $bi->variant_id ? (int) $bi->variant_id : null, $locationId), max(1, (int) $bi->quantity));
                $slots = $slots === null ? $n : min($slots, $n);
            }

            return max(0, $slots ?? 0);
        }
        if ($variantId) {
            return $state['inventory'][$variantId . '|' . $locationId] ?? 0;
        }
        $own = $state['variants']->where('product_id', $productId);
        $real = $own->where('name', '<>', 'Default');
        if ($real->isNotEmpty()) {
            return (int) $real->sum(fn ($v) => $this->availableAt($state, $productId, (int) $v->id, $locationId));
        }
        $default = $own->firstWhere('name', 'Default');
        if ($default) {
            return $state['inventory'][$default->id . '|' . $locationId] ?? 0;
        }

        // Legacy simple product never moved to per-location rows: stock sits on products.stock at products.location_id
        return (int) $product->location_id === $locationId ? (int) $product->stock : 0;
    }

    private function deductFlat(int $companyId, array $line): void
    {
        $qty = $line['quantity'];
        if ($line['variant_id']) {
            VariantInventory::where('variant_id', $line['variant_id'])
                ->orderByDesc('quantity')
                ->lockForUpdate()
                ->get()
                ->each(function (VariantInventory $inv) use (&$qty) {
                    if ($qty <= 0) {
                        return false;
                    }
                    $take = min($qty, (int) $inv->quantity);
                    $inv->decrement('quantity', $take);
                    $qty -= $take;
                });
            ProductVariant::whereKey($line['variant_id'])->decrement('stock', $line['quantity']);

            return;
        }
        Product::whereKey($line['product_id'])->where('company_id', $companyId)->decrement('stock', $line['quantity']);
    }

    private function shortage(array $state, array $line, int $have): array
    {
        $product = $state['products']->get($line['product_id']);
        $name = $product?->name ?? "Product #{$line['product_id']}";
        if ($line['variant_id']) {
            $variantName = $state['variants']->firstWhere('id', $line['variant_id'])?->name;
            $name .= $variantName && $variantName !== 'Default' ? " ({$variantName})" : '';
        }

        return [
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'product_name' => $name,
            'requested_quantity' => $line['quantity'],
            'available_quantity' => max(0, $have),
        ];
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Stock must be locked and taken inside a database transaction.');
        }
    }
}
