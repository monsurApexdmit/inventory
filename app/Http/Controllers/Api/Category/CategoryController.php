<?php

namespace App\Http\Controllers\Api\Category;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\BulkDeleteCategoryRequest;
use App\Http\Requests\Category\CreateCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Category\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CategoryService $categoryService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        $result = $this->categoryService->list($companyId, $filters);
        // meta is already inside data from pagination, just return as-is
        return $this->success($result);
    }

    public function simple(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->categoryService->simple($companyId));
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->categoryService->stats($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->categoryService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->categoryService->create($companyId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Category created successfully',
            201
        );
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->categoryService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->categoryService->toggleStatus($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->categoryService->delete($id, $companyId);

        return $this->success(['message' => 'Category deleted successfully']);
    }

    public function bulkDelete(BulkDeleteCategoryRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $deleted = $this->categoryService->bulkDelete($request->validated()['ids'], $companyId);

        return $this->success([
            'message' => 'Categories deleted successfully',
            'deleted' => $deleted,
        ]);
    }
}
