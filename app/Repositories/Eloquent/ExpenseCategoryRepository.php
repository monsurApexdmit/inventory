<?php

namespace App\Repositories\Eloquent;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\IExpenseCategoryRepository;

class ExpenseCategoryRepository implements IExpenseCategoryRepository
{
    public function __construct(private readonly ExpenseCategory $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model->where('company_id', $companyId);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where('name', 'like', '%' . $search . '%');
        }

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?ExpenseCategory
    {
        return $this->model
            ->where('company_id', $companyId)
            ->find($id);
    }

    public function findByNameAndCompany(string $name, int $companyId): ?ExpenseCategory
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->first();
    }

    public function create(array $data): ExpenseCategory
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ExpenseCategory
    {
        $category = $this->model->findOrFail($id);
        $category->fill($data)->save();

        return $category;
    }

    public function delete(int $id): bool
    {
        $category = $this->model->findOrFail($id);

        return (bool) $category->delete();
    }
}
