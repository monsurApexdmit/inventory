<?php

namespace App\Http\Controllers\Api\V1\CustomerReturn;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerReturn\CreateCustomerReturnRequest;
use App\Http\Requests\CustomerReturn\UpdateCustomerReturnRequest;
use App\Http\Traits\ApiResponse;
use App\Services\CustomerReturn\CustomerReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CustomerReturnService $customerReturnService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $returns = $this->customerReturnService->list($companyId, $filters);

        return $this->success($returns);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerReturnService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateCustomerReturnRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerReturnService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Customer return created successfully', 201);
    }

    public function update(UpdateCustomerReturnRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerReturnService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Customer return updated successfully');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerReturnService->approve($id, $companyId);

        return $this->success($dto->toArray(), 'Customer return approved and inventory restocked successfully');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->customerReturnService->reject($id, $companyId, $request->all());

        return $this->success($dto->toArray(), 'Customer return rejected successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->customerReturnService->delete($id, $companyId);

        return $this->success(['message' => 'Customer return deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $stats = $this->customerReturnService->getStats($companyId);

        return $this->success($stats);
    }

    public function getByCustomer(Request $request, int $customerId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $returns = $this->customerReturnService->getByCustomer($customerId, $companyId, $filters);

        return $this->success($returns);
    }
}
