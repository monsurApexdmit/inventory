<?php

namespace App\Http\Controllers\Api\V1\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\CreateExpenseCategoryRequest;
use App\Http\Requests\Expense\UpdateExpenseCategoryRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Expense\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ExpenseCategoryService $expenseCategoryService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $categories = $this->expenseCategoryService->list($companyId, $filters);

        return $this->success($categories);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseCategoryService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateExpenseCategoryRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseCategoryService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Expense category created successfully', 201);
    }

    public function update(UpdateExpenseCategoryRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseCategoryService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Expense category updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->expenseCategoryService->delete($id, $companyId);

        return $this->success(['message' => 'Expense category deleted successfully']);
    }
}
