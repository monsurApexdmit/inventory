<?php

namespace App\Services\Category;

use App\DTOs\Category\CategoryDTO;
use App\DTOs\Category\CategoryMapper;
use App\Repositories\Contracts\ICategoryRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'description' => $data['description'] ?? null,
            'image' => $this->resolveImage($data['image'] ?? null),
            'color' => $data['color'] ?? null,
        ];
        $dbData['sort_order'] = $this->categoryRepository->nextSortOrder($companyId, $dbData['parent_id'] ? (int) $dbData['parent_id'] : null);

        // Check for duplicate name
        if ($this->categoryRepository->existsByNameAndCompany($dbData['category_name'], $companyId)) {
            throw new HttpException(422, 'Category with this name already exists');
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
        if (array_key_exists('parentId', $data)) {
            $dbData['parent_id'] = $data['parentId'];
        }
        if (array_key_exists('color', $data)) {
            $dbData['color'] = $data['color'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (array_key_exists('description', $data)) {
            $dbData['description'] = $data['description'];
        }
        if (array_key_exists('image', $data)) {
            $dbData['image'] = $this->resolveImage($data['image']);
            if ($category->image && $category->image !== $dbData['image'] && !preg_match('#^https?://#i', $category->image)) {
                Storage::disk('public')->delete($category->image);
            }
        }

        // Check for duplicate name (excluding self)
        if (!empty($dbData['category_name']) && $dbData['category_name'] !== $category->category_name) {
            if ($this->categoryRepository->existsByNameAndCompany($dbData['category_name'], $companyId, $id)) {
                throw new HttpException(422, 'Category with this name already exists');
            }
        }

        // Validate parent_id and check for circular reference
        if (array_key_exists('parent_id', $dbData) && $dbData['parent_id'] !== null) {
            if ((int) $dbData['parent_id'] === $id) {
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

        if (array_key_exists('parent_id', $dbData) && (int) $dbData['parent_id'] !== (int) $category->parent_id) {
            $dbData['sort_order'] = $this->categoryRepository->nextSortOrder($companyId, $dbData['parent_id'] ? (int) $dbData['parent_id'] : null);
        }

        $this->categoryRepository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    /** Persist a manual order for a set of sibling categories (ids in the desired order). */
    public function reorder(array $ids, int $companyId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = \App\Models\Category::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw new HttpException(422, 'One or more categories were not found');
        }
        $this->categoryRepository->setSortOrders($ids, $companyId);
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

        $this->assertNoProducts($id, $category->category_name);

        $this->categoryRepository->delete($id);
    }

    /**
     * Deletes every selected category that has no products and no (remaining) sub-categories;
     * the rest are kept. Returns ['deleted' => N, 'kept' => M, 'keptNames' => [...]].
     */
    public function bulkDelete(array $ids, int $companyId): array
    {
        $pending = \App\Models\Category::where('company_id', $companyId)->whereIn('id', $ids)->get()->keyBy('id');
        $deleted = 0;
        do {
            $progress = false;
            foreach ($pending as $id => $cat) {
                $hasProducts = \App\Models\Product::where('category_id', $id)->exists();
                if ($hasProducts || $this->categoryRepository->hasChildren((int) $id)) {
                    continue;
                }
                $this->categoryRepository->delete((int) $id);
                $pending->forget($id);
                $deleted++;
                $progress = true;
            }
        } while ($progress && $pending->isNotEmpty());

        return [
            'deleted' => $deleted,
            'kept' => $pending->count(),
            'keptNames' => $pending->pluck('category_name')->values()->all(),
        ];
    }

    private function assertNoProducts(int $categoryId, string $name): void
    {
        $count = \App\Models\Product::where('category_id', $categoryId)->count();
        if ($count > 0) {
            throw new HttpException(422, "Cannot delete category '{$name}': it has {$count} product" . ($count === 1 ? '' : 's') . '. Reassign or delete them first.');
        }
    }

    /** Uploaded file -> stored path (same disk/mechanism as coupon images); string/URL passes through. */
    private function resolveImage(mixed $image): ?string
    {
        if ($image instanceof UploadedFile) {
            return $image->store('categories', 'public');
        }

        return ($image === null || $image === '') ? null : (string) $image;
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
