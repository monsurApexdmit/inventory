<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Customer\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CustomerService $customerService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $result = $this->customerService->list($companyId, $filters);

        return $this->success($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Customer created successfully', 201);
    }

    public function update(UpdateCustomerRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Customer updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->customerService->delete($id, $companyId);

        return $this->success(['message' => 'Customer deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->customerService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Customer stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
