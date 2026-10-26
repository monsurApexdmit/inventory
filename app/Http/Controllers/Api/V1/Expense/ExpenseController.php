<?php

namespace App\Http\Controllers\Api\V1\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\CreateExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ExpenseService $expenseService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $expenses = $this->expenseService->list($companyId, $filters);

        // data keeps its original shape; pagination, stats, byCategory, trend and counts are mirrored in meta.
        $s = $expenses['stats'];
        return $this->success($expenses, 'Success', 200, [
            'total' => $expenses['total'],
            'per_page' => $expenses['per_page'],
            'current_page' => $expenses['current_page'],
            'last_page' => $expenses['last_page'],
            'stats' => $s['stats'],
            'byCategory' => $s['byCategory'],
            'trend' => $s['trend'],
            'counts' => $s['counts'],
        ]);
    }

    public function templates(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->expenseService->templates($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateExpenseRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseService->create($companyId, $request->validated(), $request->file('receipt'));

        return $this->success($dto->toArray(), 'Expense created successfully', 201);
    }

    public function update(UpdateExpenseRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->expenseService->update($id, $companyId, $request->validated(), $request->file('receipt'));

        return $this->success($dto->toArray(), 'Expense updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->expenseService->delete($id, $companyId);

        return $this->success(['message' => 'Expense deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->expenseService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Expense stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
