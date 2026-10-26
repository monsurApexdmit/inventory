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
        $query = $this->model->where('company_id', $companyId)->withCount('products');

        // Handle view parameter
        $view = $filters['view'] ?? 'all';
        if ($view === 'tree') {
            $query->whereNull('parent_id')->with('children');
        } elseif ($view === 'flat') {
            $query->with('parent');
        }

        // Handle search
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('category_name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        // Handle status filter
        if (isset($filters['include_inactive']) && $filters['include_inactive'] === false) {
            $query->where('status', true);
        }

        $limit = $filters['limit'] ?? 15;

        $query->orderBy('sort_order')->orderBy('category_name');

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
            ->orderBy('sort_order')->orderBy('category_name')
            ->get()
            ->all();
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
            ->whereRaw('LOWER(category_name) = ?', [mb_strtolower($name)]);

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

    public function nextSortOrder(int $companyId, ?int $parentId): int
    {
        return ((int) $this->model->where('company_id', $companyId)
            ->where('parent_id', $parentId)->max('sort_order')) + 1;
    }

    public function setSortOrders(array $ids, int $companyId): void
    {
        foreach (array_values($ids) as $i => $id) {
            $this->model->where('company_id', $companyId)->where('id', $id)->update(['sort_order' => $i + 1]);
        }
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
