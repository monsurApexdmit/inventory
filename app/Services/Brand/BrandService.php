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
            'status'    => (bool) $b->status,
            'createdAt' => $b->created_at?->toIso8601String(),
            'updatedAt' => $b->updated_at?->toIso8601String(),
        ];
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = (int) ($filters['limit'] ?? $filters['per_page'] ?? 100);

        $query = Brand::where('company_id', $companyId)->orderBy('brand_name');
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
        $brand = Brand::create([
            'company_id' => $companyId,
            'brand_name' => $data['brandName'],
            'logo'       => $data['logo'] ?? null,
            'status'     => $data['status'] ?? true,
        ]);

        return $this->toArray($brand);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $brand = $this->find($id, $companyId);

        if (array_key_exists('brandName', $data)) $brand->brand_name = $data['brandName'];
        if (array_key_exists('logo', $data))      $brand->logo = $data['logo'];
        if (array_key_exists('status', $data))    $brand->status = (bool) $data['status'];
        $brand->save();

        return $this->toArray($brand);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->find($id, $companyId)->delete();
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
