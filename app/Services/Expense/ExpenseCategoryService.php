<?php

namespace App\Services\Expense;

use App\DTOs\Expense\ExpenseCategoryDTO;
use App\DTOs\Expense\ExpenseCategoryMapper;
use App\Repositories\Contracts\IExpenseCategoryRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExpenseCategoryService
{
    private readonly ExpenseCategoryMapper $mapper;

    public function __construct(private readonly IExpenseCategoryRepository $repository)
    {
        $this->mapper = new ExpenseCategoryMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($category) => $this->mapper->toDTO($category), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'stats' => $this->repository->getStats($companyId),
        ];
    }

    public function get(int $id, int $companyId): ExpenseCategoryDTO
    {
        $category = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Expense category not found');
        }

        return $this->mapper->toDTO($category);
    }

    public function create(int $companyId, array $data): ExpenseCategoryDTO
    {
        $existing = $this->repository->findByNameAndCompany($data['name'], $companyId);
        if ($existing) {
            throw new HttpException(409, 'Expense category name already exists for this company');
        }

        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;

        $category = $this->repository->create($dbData);

        return $this->mapper->toDTO($category);
    }

    public function update(int $id, int $companyId, array $data): ExpenseCategoryDTO
    {
        $category = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Expense category not found');
        }

        if ($category->isSystem()) {
            if (isset($data['name']) && $data['name'] !== $category->name) {
                throw new HttpException(422, "The '{$category->name}' category is managed by payroll and cannot be renamed.");
            }
            if (array_key_exists('status', $data) && $data['status'] !== null && !(bool) $data['status']) {
                throw new HttpException(422, "The '{$category->name}' category is managed by payroll and cannot be deactivated.");
            }
        }

        if (isset($data['name']) && $data['name'] !== $category->name) {
            $existing = $this->repository->findByNameAndCompany($data['name'], $companyId);
            if ($existing) {
                throw new HttpException(409, 'Expense category name already exists for this company');
            }
        }

        $dbData = $this->mapInputToDb($data);

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $category = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Expense category not found');
        }

        if ($category->isSystem()) {
            throw new HttpException(422, "The '{$category->name}' category is managed by payroll and cannot be deleted.");
        }

        $count = $this->repository->countExpenses($id);
        if ($count > 0) {
            throw new HttpException(422, "Cannot delete category '{$category->name}': it has {$count} expense" . ($count === 1 ? '' : 's') . '. Reassign or delete them first.');
        }

        $this->repository->delete($id);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $dbData['description'] = $data['description'];
        }

        if (array_key_exists('color', $data)) {
            $dbData['color'] = $data['color'];
        }
        if (array_key_exists('monthlyBudget', $data)) {
            $dbData['monthly_budget'] = $data['monthlyBudget'];
        }
        if (!empty($data['type'])) {
            $dbData['type'] = $data['type'];
        }
        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $dbData['status'] = (bool) $data['status'];
        }

        return $dbData;
    }
}
