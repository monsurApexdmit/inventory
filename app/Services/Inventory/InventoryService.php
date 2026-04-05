<?php

namespace App\Services\Inventory;

use App\DTOs\Inventory\InventoryItemDTO;
use App\DTOs\Inventory\LocationInventoryDTO;
use Illuminate\Support\Facades\DB;
use stdClass;

class InventoryService
{
    /**
     * Get paginated inventory with per-location breakdown
     * Computes a flat list from products, product_variants, variant_inventory, and locations
     */
    public function getInventory(int $companyId, array $filters): array
    {
        // Extract and validate filters
        $page = (int) ($filters['page'] ?? 1);
        $limit = min((int) ($filters['limit'] ?? 10), 100); // Cap at 100
        $search = $filters['search'] ?? null;
        $locationId = isset($filters['location_id']) ? (int) $filters['location_id'] : null;

        // Get all inventory items (before pagination for accurate total count)
        $allResults = $this->getAllInventoryResults($companyId, $search, $locationId);

        // Calculate total and pagination
        $total = count($allResults);
        $offset = ($page - 1) * $limit;
        $paginatedResults = array_slice($allResults, $offset, $limit);

        // Transform raw results into InventoryItemDTOs
        $items = $this->formatResults($paginatedResults);

        return [
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Get all inventory results (combining simple products and variants)
     *
     * Priority:
     * 1. Variants with actual inventory_inventory records (even if qty = 0) - source of truth
     * 2. Variants without any inventory records - fallback to product_variants.stock
     * 3. Simple products - use products.stock
     */
    private function getAllInventoryResults(int $companyId, ?string $search, ?int $locationId): array
    {
        // Get simple products inventory
        $simpleResults = $this->getSimpleProductInventory($companyId, $search, $locationId);

        // Get variant products inventory (with inventory records)
        $variantResults = $this->getVariantProductInventory($companyId, $search, $locationId);

        // Get variant products without any inventory records (fallback to variant.stock)
        $variantFallbackResults = $this->getVariantProductFallbackInventory($companyId, $search, $locationId);

        // Track which variants have inventory records
        $variantsWithInventory = [];
        foreach ($variantResults as $row) {
            $variantsWithInventory[$row->id] = true;
        }

        // Filter out fallback results for variants that already have inventory records
        // (inventory records are the source of truth, even if they show 0)
        $filteredFallbackResults = array_filter($variantFallbackResults, function ($row) use ($variantsWithInventory) {
            return !isset($variantsWithInventory[$row->id]);
        });

        // Combine and deduplicate by (type, id)
        $combined = [];
        foreach (array_merge($simpleResults, $variantResults, $filteredFallbackResults) as $row) {
            $key = "{$row->type}_{$row->id}";
            if (!isset($combined[$key])) {
                $combined[$key] = [];
            }
            $combined[$key][] = $row;
        }

        return $combined;
    }

    /**
     * Get simple product inventory
     * Simple products use the stock field from products table,
     * but to match variant_inventory structure, we track by location
     */
    private function getSimpleProductInventory(int $companyId, ?string $search, ?int $locationId): array
    {
        // For simple products without variants, use location from product table
        $query = DB::table('products as p')
            ->leftJoin('locations as l', 'p.location_id', '=', 'l.id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->where('p.stock', '>', 0) // Only products with stock
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_variants as pv_check')
                    ->whereColumn('pv_check.product_id', 'p.id')
                    ->whereNull('pv_check.deleted_at');
            })
            ->select(
                DB::raw("'product' as type"),
                'p.id',
                DB::raw('p.id as productId'),
                'p.name as productName',
                DB::raw("'' as variantName"),
                'p.sku',
                'p.barcode',
                DB::raw('p.stock as stock'),
                'p.location_id',
                'l.name as locationName',
                'p.stock as quantity'
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('p.sku', 'like', "%{$search}%");
            });
        }

        if ($locationId) {
            $query->where('p.location_id', $locationId);
        }

        return $query->get()->toArray();
    }

    /**
     * Get variant product inventory
     */
    private function getVariantProductInventory(int $companyId, ?string $search, ?int $locationId): array
    {
        $query = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->leftJoin('variant_inventory as vi', 'pv.id', '=', 'vi.variant_id')
            ->leftJoin('locations as l', 'vi.location_id', '=', 'l.id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereNotNull('vi.id')
            ->select(
                DB::raw("'variant' as type"),
                'pv.id',
                'pv.product_id as productId',
                'p.name as productName',
                'pv.name as variantName',
                'pv.sku',
                'pv.barcode',
                'vi.location_id',
                'l.name as locationName',
                'vi.quantity'
            )
            ->groupBy('pv.id', 'pv.product_id', 'p.name', 'pv.name', 'pv.sku', 'pv.barcode', 'vi.location_id', 'l.name', 'vi.quantity');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('pv.name', 'like', "%{$search}%")
                    ->orWhere('pv.sku', 'like', "%{$search}%");
            });
        }

        if ($locationId) {
            $query->where('vi.location_id', $locationId);
        }

        return $query->get()->toArray();
    }

    /**
     * Get variant products where inventory_inventory has zero quantity in all locations
     * (fallback to product_variants.stock)
     */
    private function getVariantProductFallbackInventory(int $companyId, ?string $search, ?int $locationId): array
    {
        // Get all locations for the company
        $locations = DB::table('locations')
            ->where('company_id', $companyId)
            ->select('id', 'name')
            ->get();

        if ($locations->isEmpty()) {
            return [];
        }

        $locationIds = $locations->pluck('id')->toArray();

        // Find variants that have no inventory records with quantity > 0
        // AND have stock in product_variants.stock
        $query = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->where('p.company_id', $companyId)
            ->where('pv.stock', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('variant_inventory as vi_check')
                    ->whereColumn('vi_check.variant_id', 'pv.id')
                    ->where('vi_check.quantity', '>', 0);
            });

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                    ->orWhere('pv.name', 'like', "%{$search}%")
                    ->orWhere('pv.sku', 'like', "%{$search}%");
            });
        }

        $variants = $query
            ->select('pv.id', 'pv.product_id as productId', 'p.name as productName', 'pv.name as variantName', 'pv.sku', 'pv.barcode', 'pv.stock')
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        // For each variant, create one row per location with fallback stock
        $results = [];
        foreach ($variants as $variant) {
            // Check if we should filter by location
            $locationsToUse = $locationId ? array_filter($locations->toArray(), fn($l) => $l->id == $locationId) : $locations->toArray();

            foreach ($locationsToUse as $location) {
                $results[] = (object) [
                    'type' => 'variant',
                    'id' => $variant->id,
                    'productId' => $variant->productId,
                    'productName' => $variant->productName,
                    'variantName' => $variant->variantName,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'location_id' => $location->id,
                    'locationName' => $location->name,
                    'quantity' => (int) $variant->stock, // Use variant.stock as fallback
                ];
            }
        }

        return $results;
    }

    /**
     * Format raw database results into InventoryItemDTOs
     */
    private function formatResults(array $combined): array
    {
        if (empty($combined)) {
            return [];
        }

        $items = [];

        // Process each grouped result (each key is a set of rows for one inventory item)
        foreach ($combined as $rowSet) {
            if (empty($rowSet)) {
                continue;
            }

            // Handle both array and object access
            $firstRow = is_array($rowSet[0]) ? (object) $rowSet[0] : $rowSet[0];

            // Skip if the id is null (shouldn't happen but defensive check)
            if (!isset($firstRow->id) || $firstRow->id === null) {
                continue;
            }

            // Group locations for this item
            $locations = [];
            foreach ($rowSet as $row) {
                $row = is_array($row) ? (object) $row : $row;
                if (isset($row->location_id) && $row->location_id !== null && isset($row->locationName) && $row->locationName !== null) {
                    $locations[] = [
                        'locationId' => (int) $row->location_id,
                        'locationName' => (string) $row->locationName,
                        'quantity' => (int) $row->quantity,
                    ];
                }
            }

            // Skip if no locations (product/variant with no inventory)
            if (empty($locations)) {
                continue;
            }

            // Calculate total stock
            $totalStock = array_sum(array_column($locations, 'quantity'));

            // Create LocationInventoryDTOs
            $inventoryDtos = array_map(
                fn($loc) => new LocationInventoryDTO(
                    locationId: $loc['locationId'],
                    locationName: $loc['locationName'],
                    quantity: $loc['quantity']
                ),
                $locations
            );

            // Create InventoryItemDTO
            $items[] = new InventoryItemDTO(
                type: (string) $firstRow->type,
                id: (int) $firstRow->id,
                productId: (int) $firstRow->productId,
                productName: (string) $firstRow->productName,
                variantName: isset($firstRow->variantName) ? (string) $firstRow->variantName : '',
                sku: (string) $firstRow->sku,
                barcode: isset($firstRow->barcode) ? (string) $firstRow->barcode : null,
                stock: $totalStock,
                inventory: $inventoryDtos
            );
        }

        return $items;
    }
}
