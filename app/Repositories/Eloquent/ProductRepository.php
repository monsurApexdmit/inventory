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
        $query = $this->model
            ->where('company_id', $companyId)
            ->with([
                'category',
                'vendor',
                'location',
                'variants' => function ($q) {
                    $q->with('inventory');
                },
                'images',
            ]);

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['published'])) {
            $query->where('published', (bool) $filters['published']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        $limit = $filters['limit'] ?? 15;
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Product
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'vendor', 'location', 'variants.inventory', 'images'])
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
