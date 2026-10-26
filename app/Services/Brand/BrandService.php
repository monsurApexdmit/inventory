<?php

namespace App\Services\Brand;

use App\Models\Brand;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Company-scoped CRUD for product brands. Self-contained (no repository/DTO
 * layer) — brands are a flat lookup.
 */
class BrandService
{
    private function toArray(Brand $b): array
    {
        return [
            'id'        => $b->id,
            'brandName' => $b->brand_name,
            'logo'      => $b->logo,
            'website'   => $b->website,
            'description' => $b->description,
            'status'    => (bool) $b->status,
            'productsCount' => isset($b->products_count) ? (int) $b->products_count : null,
            'stockValue' => isset($b->stock_value) ? round((float) $b->stock_value, 2) : null,
            'createdAt' => $b->created_at?->toIso8601String(),
            'updatedAt' => $b->updated_at?->toIso8601String(),
        ];
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = (int) ($filters['limit'] ?? $filters['per_page'] ?? 100);

        $query = Brand::where('company_id', $companyId)->withCount('products')
            ->withSum('products as stock_value', \DB::raw('stock * cost_price'))->orderBy('brand_name');
        if (!empty($filters['search'])) {
            $query->where('brand_name', 'like', '%' . $filters['search'] . '%');
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginated = $query->paginate($perPage);

        return [
            'data'         => array_map(fn ($b) => $this->toArray($b), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'no_brand_count' => \App\Models\Product::where('company_id', $companyId)->whereNull('brand_id')->count(),
        ];
    }

    /** Lightweight active-brand list for dropdowns. */
    public function simple(int $companyId): array
    {
        return Brand::where('company_id', $companyId)
            ->where('status', true)
            ->orderBy('brand_name')
            ->get()
            ->map(fn ($b) => $this->toArray($b))
            ->all();
    }

    public function get(int $id, int $companyId): array
    {
        return $this->toArray($this->find($id, $companyId));
    }

    public function create(int $companyId, array $data): array
    {
        $this->assertUniqueName($companyId, $data['brandName']);
        $brand = Brand::create([
            'company_id' => $companyId,
            'brand_name' => $data['brandName'],
            'logo'       => $data['logo'] ?? null,
            'website'    => $data['website'] ?? null,
            'description' => $data['description'] ?? null,
            'status'     => $data['status'] ?? true,
        ]);

        return $this->toArray($brand);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $brand = $this->find($id, $companyId);

        if (array_key_exists('brandName', $data)) $this->assertUniqueName($companyId, $data['brandName'], $id);
        if (array_key_exists('brandName', $data)) $brand->brand_name = $data['brandName'];
        if (array_key_exists('logo', $data))      $brand->logo = $data['logo'];
        if (array_key_exists('website', $data))   $brand->website = $data['website'];
        if (array_key_exists('description', $data)) $brand->description = $data['description'];
        if (array_key_exists('status', $data))    $brand->status = (bool) $data['status'];
        $brand->save();

        return $this->toArray($brand);
    }

    public function delete(int $id, int $companyId): void
    {
        $brand = $this->find($id, $companyId);
        $count = $brand->products()->count();
        if ($count > 0) {
            throw new HttpException(422, "Cannot delete brand '{$brand->brand_name}': it has {$count} product" . ($count === 1 ? '' : 's') . '. Reassign or delete them first.');
        }
        $brand->delete();
    }

    private function assertUniqueName(int $companyId, string $name, ?int $exceptId = null): void
    {
        $q = Brand::where('company_id', $companyId)->whereRaw('LOWER(brand_name) = ?', [mb_strtolower(trim($name))]);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        if ($q->exists()) {
            throw new HttpException(422, 'A brand with this name already exists.');
        }
    }

    private function find(int $id, int $companyId): Brand
    {
        $brand = Brand::where('company_id', $companyId)->find($id);
        if (!$brand) {
            throw new HttpException(404, 'Brand not found');
        }
        return $brand;
    }
}
