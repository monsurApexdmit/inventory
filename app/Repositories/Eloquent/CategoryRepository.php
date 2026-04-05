<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;
use App\Repositories\Contracts\ICategoryRepository;

class CategoryRepository implements ICategoryRepository
{
    public function __construct(private readonly Category $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model->where('company_id', $companyId);

        // Handle view parameter
        $view = $filters['view'] ?? 'all';
        if ($view === 'tree') {
            $query->whereNull('parent_id')->with('children');
        } elseif ($view === 'flat') {
            $query->with('parent');
        }

        // Handle search
        if (!empty($filters['search'])) {
            $query->where('category_name', 'like', "%{$filters['search']}%");
        }

        // Handle status filter
        if (isset($filters['include_inactive']) && $filters['include_inactive'] === false) {
            $query->where('status', true);
        }

        $limit = $filters['limit'] ?? 15;

        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Category
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with(['parent', 'children'])
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
        $categories = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get();

        $total = $categories->count();
        $active = $categories->where('status', true)->count();
        $inactive = $categories->where('status', false)->count();
        $root = $categories->whereNull('parent_id')->count();
        $sub = $total - $root;

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'root_categories' => $root,
            'subcategories' => $sub,
        ];
    }

    public function existsByNameAndCompany(string $name, int $companyId, ?int $excludeId = null): bool
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('category_name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function hasChildren(int $id): bool
    {
        return $this->model
            ->where('parent_id', $id)
            ->whereNull('deleted_at')
            ->count() > 0;
    }

    public function create(array $data): Category
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Category
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
