<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use App\Repositories\Contracts\IProductRepository;

class ProductRepository implements IProductRepository
{
    public function __construct(private readonly Product $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->filteredQuery($companyId, $filters, true);
        $limit = $filters['limit'] ?? 15;

        return $query->paginate($limit);
    }

    /** Company products narrowed by every list filter except the stock-state tab. */
    public function filteredQuery(int $companyId, array $filters, bool $withRelations = false): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->model->newQuery()->where('company_id', $companyId);

        if ($withRelations) {
            $query->with([
                'category',
                'unit',
                'brand',
                'vendor',
                'location',
                'variants' => function ($q) {
                    $q->with('inventory');
                },
                'images',
                'bundleItems.product',
                'bundleItems.variant',
            ]);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $like))
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)->orWhere('barcode', 'like', $like));
            });
        }

        if (isset($filters['published']) && $filters['published'] !== '') {
            $query->where('published', filter_var($filters['published'], FILTER_VALIDATE_BOOLEAN));
        }

        $categoryId = $filters['category_id'] ?? $filters['categoryId'] ?? null;
        if ($categoryId !== null && $categoryId !== '') {
            $categoryId === 'none' ? $query->whereNull('category_id') : $query->where('category_id', (int) $categoryId);
        }

        $brandId = $filters['brandId'] ?? $filters['brand_id'] ?? null;
        if ($brandId !== null && $brandId !== '') {
            $brandId === 'none' ? $query->whereNull('brand_id') : $query->where('brand_id', (int) $brandId);
        }

        $supplierId = $filters['supplierId'] ?? $filters['vendor_id'] ?? $filters['vendorId'] ?? null;
        if ($supplierId !== null && $supplierId !== '') {
            $supplierId === 'none' ? $query->whereNull('vendor_id') : $query->where('vendor_id', (int) $supplierId);
        }

        // Legacy filter: products whose home location matches (POS). The warehouse used to
        // evaluate stock is the camelCase `locationId`, handled by ProductService.
        if (isset($filters['location_id']) && $filters['location_id'] !== '') {
            $query->where('location_id', $filters['location_id']);
        }

        if (isset($filters['pos']) && filter_var($filters['pos'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('available_in_pos', true);
        }

        return $query;
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Product
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'unit', 'brand', 'vendor', 'location', 'variants.inventory', 'images', 'attributes', 'bundleItems.product', 'bundleItems.variant'])
            ->find($id);
    }

    public function create(array $data): Product
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Product
    {
        $product = $this->model->findOrFail($id);
        $product->fill($data)->save();

        return $product;
    }

    public function delete(int $id): bool
    {
        $product = $this->model->findOrFail($id);

        return (bool) $product->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN published = true THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN published = false THEN 1 ELSE 0 END) as unpublished
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'published' => (int) ($stats->published ?? 0),
            'unpublished' => (int) ($stats->unpublished ?? 0),
        ];
    }
}
