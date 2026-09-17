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

        return $dbData;
    }
}
