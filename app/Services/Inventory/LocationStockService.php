<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantInventory;
use App\Support\ActorName;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-location stock helper (same storage rules as stock adjustments/transfers):
 *  - real variants: variant_inventory rows for (variant, location)
 *  - simple products: hidden "Default" variant + variant_inventory; legacy products
 *    that never had one keep stock on products.stock at products.location_id.
 * variant.stock / products.stock are re-synced after every change.
 */
class LocationStockService
{
    public function defaultLocationId(int $companyId): int
    {
        $id = Location::where('company_id', $companyId)->where('is_default', true)->value('id')
            ?? Location::where('company_id', $companyId)->orderBy('id')->value('id');
        if (!$id) {
            throw new HttpException(422, 'No stock location is configured for this company');
        }
        return (int) $id;
    }

    /** Units available at one location. */
    public function available(int $productId, ?int $variantId, int $locationId): int
    {
        if ($variantId) {
            return (int) VariantInventory::where('variant_id', $variantId)->where('location_id', $locationId)->value('quantity');
        }
        $default = ProductVariant::where('product_id', $productId)->where('name', 'Default')->first();
        if ($default) {
            return (int) VariantInventory::where('variant_id', $default->id)->where('location_id', $locationId)->value('quantity');
        }
        $product = Product::find($productId);
        if (!$product || ProductVariant::where('product_id', $productId)->exists()) {
            return 0;
        }
        return (int) $product->location_id === $locationId ? (int) $product->stock : 0;
    }

    /**
     * Apply a signed stock change at a location and log an inventory movement.
     *
     * @param array{type?:string,reference_type?:string,reference_id?:int,notes?:string,reason?:string,unit_cost?:float} $meta
     * @return array{before:int,after:int}
     */
    public function change(int $companyId, int $productId, ?int $variantId, int $locationId, int $delta, array $meta = []): array
    {
        $product = Product::where('company_id', $companyId)->whereKey($productId)->lockForUpdate()->first();
        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        if ($variantId) {
            $variant = ProductVariant::find($variantId);
            if (!$variant || (int) $variant->product_id !== $product->id) {
                throw new HttpException(404, 'Variant not found or does not belong to this product');
            }
            $isSimple = false;
        } else {
            $variant = ProductVariant::firstOrCreate(
                ['product_id' => $product->id, 'name' => 'Default'],
                ['sku' => $product->sku, 'price' => $product->price, 'stock' => 0]
            );
            $isSimple = true;
            if ($variant->wasRecentlyCreated && (int) $product->stock > 0 && !VariantInventory::where('variant_id', $variant->id)->exists()) {
                // Move the legacy products.stock into per-location storage so nothing is lost.
                VariantInventory::create([
                    'variant_id'  => $variant->id,
                    'location_id' => $product->location_id ?: $this->defaultLocationId($companyId),
                    'quantity'    => (int) $product->stock,
                ]);
            }
        }

        $inventory = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $locationId)->lockForUpdate()->first()
            ?? VariantInventory::create(['variant_id' => $variant->id, 'location_id' => $locationId, 'quantity' => 0]);

        $before = (int) $inventory->quantity;
        $after = $before + $delta;
        if ($after < 0) {
            throw new HttpException(422, "Not enough stock for '{$product->name}' at this location (available: {$before}, needed: " . abs($delta) . ')');
        }
        $inventory->quantity = $after;
        $inventory->save();

        $total = (int) VariantInventory::where('variant_id', $variant->id)->sum('quantity');
        $variant->stock = $total;
        $variant->save();

        if ($isSimple) {
            $product->stock = $total;
            $withStock = VariantInventory::where('variant_id', $variant->id)->where('quantity', '>', 0)->pluck('location_id')->toArray();
            if (count($withStock) === 1) {
                $product->location_id = $withStock[0];
            }
        } else {
            $product->stock = (int) ProductVariant::where('product_id', $product->id)->where('name', '<>', 'Default')->sum('stock');
        }
        $product->save();

        InventoryMovement::create([
            'company_id'      => $companyId,
            'product_id'      => $product->id,
            'variant_id'      => $isSimple ? null : $variant->id,
            'location_id'     => $locationId,
            'type'            => $meta['type'] ?? 'adjustment',
            'reference_type'  => $meta['reference_type'] ?? null,
            'reference_id'    => $meta['reference_id'] ?? null,
            'quantity'        => $delta,
            'quantity_before' => $before,
            'quantity_after'  => $after,
            'unit_cost'       => $meta['unit_cost'] ?? null,
            'reason'          => $meta['reason'] ?? null,
            'notes'           => $meta['notes'] ?? null,
            'created_by'      => User::whereKey(Auth::id())->exists() ? Auth::id() : null,
            'created_by_name' => ActorName::current(),
        ]);

        return ['before' => $before, 'after' => $after];
    }

    /** Add stock back; bundles restore their component products and re-sync the bundle count. */
    public function restore(int $companyId, int $productId, ?int $variantId, int $locationId, int $qty, array $meta = []): void
    {
        $product = Product::where('company_id', $companyId)->find($productId);
        if ($product && $product->is_bundle) {
            $items = ProductBundleItem::where('bundle_product_id', $product->id)->get();
            foreach ($items as $bi) {
                $this->change($companyId, (int) $bi->product_id, $bi->variant_id ? (int) $bi->variant_id : null, $locationId, (int) $bi->quantity * $qty, $meta);
            }
            $this->syncBundleStock($product);
            return;
        }
        $this->change($companyId, $productId, $variantId, $locationId, $qty, $meta);
    }

    /** Re-derive a bundle's stock from how many full bundles its components allow. */
    public function syncBundleStock(Product $bundle): void
    {
        $available = null;
        foreach (ProductBundleItem::where('bundle_product_id', $bundle->id)->get() as $bi) {
            $child = $bi->variant_id ? (int) ProductVariant::whereKey($bi->variant_id)->value('stock') : (int) Product::whereKey($bi->product_id)->value('stock');
            $slots = (int) floor($child / max(1, (int) $bi->quantity));
            $available = $available === null ? $slots : min($available, $slots);
        }
        $bundle->update(['stock' => max(0, $available ?? 0)]);
    }

    /** True once the company has any per-location stock rows (otherwise stock is still legacy/flat). */
    public function hasLocationStock(int $companyId): bool
    {
        return VariantInventory::whereIn('location_id', Location::where('company_id', $companyId)->select('id'))->exists();
    }

    /** Locations that fulfil online orders; [] when the flag does not exist yet. */
    public function onlineLocationIds(int $companyId): array
    {
        if (!Schema::hasColumn('locations', 'ships_online')) {
            return [];
        }
        return Location::where('company_id', $companyId)->where('ships_online', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Ships-online location ids when storefront stock is location based, else null (use flat stock). */
    public function storefrontLocationIds(int $companyId): ?array
    {
        $ids = $this->onlineLocationIds($companyId);
        return $ids && $this->hasLocationStock($companyId) ? $ids : null;
    }

    /** Units a product/variant offers across the given locations (bundles: full bundles the components allow). */
    public function availableAcross(Product $product, ?int $variantId, array $locationIds): int
    {
        if ($product->is_bundle) {
            $slots = null;
            foreach (ProductBundleItem::where('bundle_product_id', $product->id)->get() as $bi) {
                $child = Product::find($bi->product_id);
                $n = $child ? intdiv($this->availableAcross($child, $bi->variant_id ? (int) $bi->variant_id : null, $locationIds), max(1, (int) $bi->quantity)) : 0;
                $slots = $slots === null ? $n : min($slots, $n);
            }
            return max(0, $slots ?? 0);
        }
        if (!$variantId) {
            $real = ProductVariant::where('product_id', $product->id)->where('name', '<>', 'Default')->pluck('id');
            if ($real->isNotEmpty()) {
                return (int) $real->sum(fn ($vid) => $this->availableAcross($product, (int) $vid, $locationIds));
            }
        }
        $total = 0;
        foreach ($locationIds as $loc) {
            $total += $this->available($product->id, $variantId, (int) $loc);
        }
        return $total;
    }

    /** Throw 422 "Only N <name> in stock at <location>" when a location cannot cover qty. */
    public function assertAvailableAt(Product $product, ?int $variantId, int $locationId, int $qty): void
    {
        $have = $this->availableAcross($product, $variantId, [$locationId]);
        if ($have < $qty) {
            $name = $product->name;
            if ($variantId) {
                $vn = ProductVariant::whereKey($variantId)->value('name');
                $name .= $vn ? " ({$vn})" : '';
            }
            $loc = Location::whereKey($locationId)->value('name') ?? 'this location';
            throw new HttpException(422, "Only {$have} {$name} in stock at {$loc}");
        }
    }

    /** Take stock out of one location (bundles deduct their components) and log `sell` movements. */
    public function deduct(int $companyId, Product $product, ?int $variantId, int $locationId, int $qty, array $meta = []): void
    {
        $meta = array_merge(['type' => 'sell'], $meta);
        if ($product->is_bundle) {
            foreach (ProductBundleItem::where('bundle_product_id', $product->id)->get() as $bi) {
                $this->change($companyId, (int) $bi->product_id, $bi->variant_id ? (int) $bi->variant_id : null, $locationId, -((int) $bi->quantity * $qty), $meta);
            }
            $this->syncBundleStock($product);
            return;
        }
        $this->change($companyId, $product->id, $variantId, $locationId, -$qty, $meta);
    }

    /**
     * Take stock out of several locations, biggest first (a single location when it covers the quantity).
     * Returns [locationId => qty taken].
     */
    public function deductGreedy(int $companyId, Product $product, ?int $variantId, array $locationIds, int $qty, array $meta = []): array
    {
        $per = [];
        foreach ($locationIds as $loc) {
            $per[(int) $loc] = $this->availableAcross($product, $variantId, [(int) $loc]);
        }
        arsort($per);
        if (array_sum($per) < $qty) {
            $name = $product->name;
            throw new HttpException(422, 'Only ' . array_sum($per) . " {$name} in stock");
        }
        $taken = [];
        foreach ($per as $loc => $have) {
            if ($qty <= 0) {
                break;
            }
            $take = min($qty, $have);
            if ($take <= 0) {
                continue;
            }
            $this->deduct($companyId, $product, $variantId, $loc, $take, $meta);
            $taken[$loc] = $take;
            $qty -= $take;
        }
        return $taken;
    }

    /**
     * Put back whatever a sale took from locations (same product/variant/location it left).
     * Net of earlier reversals, so calling it twice is harmless. Returns true when the sale had location movements.
     */
    public function reverseSale(int $companyId, int $sellId, array $meta = []): bool
    {
        $rows = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'sell')->where('reference_id', $sellId)
            ->whereNotNull('location_id')
            ->get(['product_id', 'variant_id', 'location_id', 'quantity']);
        if ($rows->isEmpty()) {
            return false;
        }
        $net = [];
        foreach ($rows as $r) {
            $k = $r->product_id . '|' . ($r->variant_id ?? '') . '|' . $r->location_id;
            $net[$k] = ($net[$k] ?? 0) + (int) $r->quantity;
        }
        $bundles = [];
        foreach ($net as $k => $q) {
            if ($q >= 0) {
                continue;
            }
            [$pid, $vid, $loc] = explode('|', $k);
            $this->change($companyId, (int) $pid, $vid !== '' ? (int) $vid : null, (int) $loc, -$q, array_merge([
                'type' => 'return', 'reference_type' => 'sell', 'reference_id' => $sellId, 'reason' => 'Sale cancelled',
            ], $meta));
        }
        // components may belong to bundles that were sold: refresh their counts
        $componentIds = array_unique(array_map(fn ($k) => (int) explode('|', $k)[0], array_keys($net)));
        foreach (ProductBundleItem::whereIn('product_id', $componentIds)->pluck('bundle_product_id')->unique() as $bid) {
            if ($b = Product::where('company_id', $companyId)->find($bid)) {
                $this->syncBundleStock($b);
            }
        }
        return true;
    }
}
