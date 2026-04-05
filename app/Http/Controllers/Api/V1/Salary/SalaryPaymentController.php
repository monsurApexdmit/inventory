<?php

namespace App\Http\Controllers\Api\V1\Salary;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryPayment\CreateSalaryPaymentRequest;
use App\Http\Requests\SalaryPayment\UpdateSalaryPaymentRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Salary\SalaryPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SalaryPaymentService $salaryPaymentService) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = [
            'staff_id' => $request->query('staffId'),
            'status' => $request->query('status'),
            'month' => $request->query('month'),
            'limit' => (int) $request->query('limit', 15),
            'page' => (int) $request->query('page', 1),
        ];

        $result = $this->salaryPaymentService->list($companyId, $filters);

        return $this->success($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateSalaryPaymentRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Salary payment created successfully', 201);
    }

    public function update(UpdateSalaryPaymentRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Salary payment updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->salaryPaymentService->delete($id, $companyId);

        return $this->success(['message' => 'Salary payment deleted successfully']);
    }
}
