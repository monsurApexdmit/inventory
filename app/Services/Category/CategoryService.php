<?php

namespace App\Services\Category;

use App\DTOs\Category\CategoryDTO;
use App\DTOs\Category\CategoryMapper;
use App\Repositories\Contracts\ICategoryRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoryService
{
    private readonly CategoryMapper $mapper;

    public function __construct(
        private readonly ICategoryRepository $categoryRepository,
    ) {
        $this->mapper = new CategoryMapper();
    }

    public function list(int $companyId, array $filters = []): array
    {
        $paginated = $this->categoryRepository->findByCompany($companyId, $filters);
        $data = array_map(fn ($category) => $this->mapper->toDTO($category), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function simple(int $companyId): array
    {
        $categories = $this->categoryRepository->findSimpleByCompany($companyId);
        return array_map(fn ($category) => $this->mapper->toDTO($category), $categories);
    }

    public function stats(int $companyId): array
    {
        return $this->categoryRepository->getStatsByCompany($companyId);
    }

    public function get(int $id, int $companyId): CategoryDTO
    {
        $category = $this->categoryRepository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Category not found');
        }

        return $this->mapper->toDTO($category);
    }

    public function create(int $companyId, array $data): CategoryDTO
    {
        // Convert camelCase to snake_case
        $dbData = [
            'company_id' => $companyId,
            'category_name' => $data['categoryName'] ?? null,
            'parent_id' => $data['parentId'] ?? null,
            'status' => $data['status'] ?? true,
        ];

        // Check for duplicate name
        if ($this->categoryRepository->existsByNameAndCompany($dbData['category_name'], $companyId)) {
            throw new HttpException(409, 'Category with this name already exists');
        }

        // Validate parent_id if provided
        if (!empty($dbData['parent_id'])) {
            $parent = $this->categoryRepository->findByIdAndCompany($dbData['parent_id'], $companyId);
            if (!$parent) {
                throw new HttpException(400, 'Parent category not found or does not belong to this company');
            }
        }

        $category = $this->categoryRepository->create($dbData);

        return $this->mapper->toDTO($category);
    }

    public function update(int $id, int $companyId, array $data): CategoryDTO
    {
        $category = $this->categoryRepository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Category not found');
        }

        // Convert camelCase to snake_case
        $dbData = [];
        if (isset($data['categoryName'])) {
            $dbData['category_name'] = $data['categoryName'];
        }
        if (isset($data['parentId'])) {
            $dbData['parent_id'] = $data['parentId'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }

        // Check for duplicate name (excluding self)
        if (!empty($dbData['category_name']) && $dbData['category_name'] !== $category->category_name) {
            if ($this->categoryRepository->existsByNameAndCompany($dbData['category_name'], $companyId, $id)) {
                throw new HttpException(409, 'Category with this name already exists');
            }
        }

        // Validate parent_id and check for circular reference
        if (isset($dbData['parent_id'])) {
            if ($dbData['parent_id'] === $id) {
                throw new HttpException(400, 'A category cannot be its own parent');
            }

            if (!empty($dbData['parent_id'])) {
                $parent = $this->categoryRepository->findByIdAndCompany($dbData['parent_id'], $companyId);
                if (!$parent) {
                    throw new HttpException(400, 'Parent category not found or does not belong to this company');
                }

                // Check for circular reference
                if ($this->wouldCreateCircularReference($id, $dbData['parent_id'], $companyId)) {
                    throw new HttpException(400, 'This parent assignment would create a circular reference');
                }
            }
        }

        $this->categoryRepository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function toggleStatus(int $id, int $companyId): CategoryDTO
    {
        $category = $this->categoryRepository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Category not found');
        }

        $this->categoryRepository->update($id, [
            'status' => !$category->status,
        ]);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $category = $this->categoryRepository->findByIdAndCompany($id, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Category not found');
        }

        if ($this->categoryRepository->hasChildren($id)) {
            throw new HttpException(400, 'Cannot delete category that has subcategories');
        }

        $this->categoryRepository->delete($id);
    }

    public function bulkDelete(array $ids, int $companyId): int
    {
        // Check if any category has children
        foreach ($ids as $id) {
            if ($this->categoryRepository->hasChildren($id)) {
                throw new HttpException(400, 'One or more categories have subcategories and cannot be deleted');
            }
        }

        return $this->categoryRepository->bulkDelete($ids, $companyId);
    }

    private function wouldCreateCircularReference(int $categoryId, int $newParentId, int $companyId): bool
    {
        $currentParentId = $newParentId;
        $maxIterations = 10;
        $iterations = 0;

        while ($currentParentId !== null && $iterations < $maxIterations) {
            if ($currentParentId === $categoryId) {
                return true;
            }

            $parent = $this->categoryRepository->findByIdAndCompany($currentParentId, $companyId);
            if (!$parent) {
                break;
            }

            $currentParentId = $parent->parent_id;
            $iterations++;
        }

        return false;
    }
}
