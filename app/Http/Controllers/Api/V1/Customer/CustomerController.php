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

    public function duplicates(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->customerService->findDuplicates($companyId));
    }

    public function merge(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'primaryId' => 'required|integer',
            'duplicateIds' => 'required|array|min:1',
            'duplicateIds.*' => 'integer',
        ]);

        return $this->success(
            $this->customerService->merge($companyId, (int) $data['primaryId'], $data['duplicateIds']),
            'Customers merged successfully'
        );
    }

    public function bulkTag(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'tag' => 'required|string|max:50',
        ]);

        return $this->success(['updated' => $this->customerService->bulkTag($companyId, $data['ids'], $data['tag'])], 'Tag added');
    }

    public function smsNumbers(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->customerService->smsNumbers($companyId, $request->query()));
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate(['status' => 'required|in:active,inactive']);

        return $this->success($this->customerService->setStatus($id, $companyId, $data['status'])->toArray(), 'Customer status updated');
    }

    public function import(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'rows' => 'required|array|min:1|max:5000',
            'rows.*' => 'array',
        ]);

        $result = $this->customerService->import($companyId, $data['rows']);

        return $this->success($result, "Imported {$result['imported']}, skipped {$result['skipped']}");
    }
}
