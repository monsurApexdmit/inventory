<?php

namespace App\Repositories\Eloquent;

use App\Models\Attribute;
use App\Repositories\Contracts\IAttributeRepository;

class AttributeRepository implements IAttributeRepository
{
    public function __construct(private readonly Attribute $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model->where('company_id', $companyId);

        // Handle search
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('display_name', 'like', "%{$filters['search']}%");
            });
        }

        // Handle status filter
        if (isset($filters['include_inactive']) && $filters['include_inactive'] === false) {
            $query->where('status', true);
        }

        $limit = $filters['limit'] ?? 15;

        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Attribute
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    public function findSimpleByCompany(int $companyId): array
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('status', true)
            ->get();
    }

    public function getStatsByCompany(int $companyId): array
    {
        $attributes = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get();

        $total = $attributes->count();
        $active = $attributes->where('status', true)->count();
        $inactive = $attributes->where('status', false)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
        ];
    }

    public function existsByNameAndCompany(string $name, int $companyId, ?int $excludeId = null): bool
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function create(array $data): Attribute
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Attribute
    {
        $record = $this->model->findOrFail($id);
        $record->update($data);

        return $record;
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    public function bulkDelete(array $ids, int $companyId): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->where('company_id', $companyId)
            ->delete();
    }
}
