<?php

namespace App\Repositories\Eloquent;

use App\Models\StockTransfer;
use App\Repositories\Contracts\IStockTransferRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockTransferRepository extends BaseRepository implements IStockTransferRepository
{
    public function __construct()
    {
        parent::__construct(new StockTransfer());
    }

    /**
     * Find transfer by ID and company scope
     */
    public function findByIdAndCompany(int $id, int $companyId): ?StockTransfer
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['product', 'variant', 'fromLocation', 'toLocation', 'company'])
            ->find($id);
    }

    /**
     * Find all transfers for a company with filters
     */
    public function findByCompany(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['product', 'variant', 'fromLocation', 'toLocation', 'company']);

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('fromLocation', fn($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('toLocation', fn($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply from_location_id filter
        if (!empty($filters['from_location_id'])) {
            $query->where('from_location_id', $filters['from_location_id']);
        }

        // Apply to_location_id filter
        if (!empty($filters['to_location_id'])) {
            $query->where('to_location_id', $filters['to_location_id']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        // Paginate
        $perPage = $filters['per_page'] ?? 20;
        return $query->paginate($perPage);
    }

    /**
     * Get products available in a location by location ID and company scope
     *
     * Returns products grouped with their variants:
     * - Products with variants: includes 'variants' array of variant objects
     * - Simple products (State A): flat product object without 'variants' key
     * - State B simple products (transferred): appear as flat objects with stock from variant inventory
     */
    public function getProductsByLocation(int $companyId, int $locationId, array $filters = []): LengthAwarePaginator
    {
        // Query 1: Get all variants with inventory at this location (includes State B 'Default' variants)
        $variantResults = DB::table('product_variants as pv')
            ->join('products as p', 'pv.product_id', '=', 'p.id')
            ->join('variant_inventory as vi', 'pv.id', '=', 'vi.variant_id')
            ->where('p.company_id', $companyId)
            ->where('vi.location_id', $locationId)
            ->where('vi.quantity', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select(
                'p.id as product_id',
                'p.name as product_name',
                'p.sku as product_sku',
                'pv.id as variant_id',
                'pv.name as variant_name',
                'pv.sku as variant_sku',
                'vi.quantity as stock'
            )
            ->get()
            ->toArray();

        // Query 2: Get State A simple products (never transferred) at this location
        $simpleResults = DB::table('products as p')
            ->where('p.company_id', $companyId)
            ->where('p.location_id', $locationId)
            ->where('p.stock', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('product_variants as pv_check')
                    ->whereColumn('pv_check.product_id', 'p.id')
                    ->whereNull('pv_check.deleted_at');
            })
            ->select(
                'p.id as product_id',
                'p.name as product_name',
                'p.sku as product_sku',
                'p.stock as stock'
            )
            ->get()
            ->toArray();

        // Group variant results by product_id
        $groupedByProduct = [];
        foreach ($variantResults as $row) {
            $productId = $row->product_id;
            if (!isset($groupedByProduct[$productId])) {
                $groupedByProduct[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $row->product_name,
                    'product_sku' => $row->product_sku,
                    'variants' => [],
                ];
            }
            $groupedByProduct[$productId]['variants'][] = [
                'id' => $row->variant_id,
                'name' => $row->variant_name,
                'sku' => $row->variant_sku,
                'stock' => $row->stock,
            ];
        }

        // Build final items array
        $items = [];

        // Process grouped variant results
        foreach ($groupedByProduct as $productId => $group) {
            // If product has only one variant and it's named 'Default', emit as simple product
            if (count($group['variants']) === 1 && $group['variants'][0]['name'] === 'Default') {
                $items[] = [
                    'id' => (int) $group['product_id'],
                    'name' => $group['product_name'],
                    'sku' => $group['product_sku'],
                    'stock' => (int) $group['variants'][0]['stock'],
                    'location_id' => $locationId,
                ];
            } else {
                // Product has true variants: emit with variants array
                $totalStock = array_sum(array_column($group['variants'], 'stock'));
                $items[] = [
                    'id' => (int) $group['product_id'],
                    'name' => $group['product_name'],
                    'sku' => $group['product_sku'],
                    'stock' => (int) $totalStock,
                    'location_id' => $locationId,
                    'variants' => array_map(fn($v) => [
                        'id' => (int) $v['id'],
                        'name' => $v['name'],
                        'sku' => $v['sku'],
                        'stock' => (int) $v['stock'],
                    ], $group['variants']),
                ];
            }
        }

        // Append State A simple products
        foreach ($simpleResults as $row) {
            $items[] = [
                'id' => (int) $row->product_id,
                'name' => $row->product_name,
                'sku' => $row->product_sku,
                'stock' => (int) $row->stock,
                'location_id' => $locationId,
            ];
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $items = array_filter($items, function ($item) use ($search) {
                return stripos($item['name'], $search) !== false || stripos($item['sku'], $search) !== false;
            });
        }

        // Re-index array after filtering
        $items = array_values($items);

        // Apply sorting (simple in-memory sort)
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortDir = $filters['sort_dir'] ?? 'asc';
        usort($items, function ($a, $b) use ($sortBy, $sortDir) {
            $aVal = $a[$sortBy] ?? '';
            $bVal = $b[$sortBy] ?? '';
            $cmp = strcmp($aVal, $bVal);
            return $sortDir === 'desc' ? -$cmp : $cmp;
        });

        // Build paginator
        $perPage = (int) ($filters['per_page'] ?? 20);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $total = count($items);
        $offset = ($currentPage - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator($slice, $total, $perPage, $currentPage, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Update transfer and return fresh instance
     */
    public function update(int $id, array $data): StockTransfer
    {
        $transfer = $this->model->findOrFail($id);
        $transfer->update($data);
        return $transfer->fresh(['product', 'variant', 'fromLocation', 'toLocation', 'company']);
    }
}
