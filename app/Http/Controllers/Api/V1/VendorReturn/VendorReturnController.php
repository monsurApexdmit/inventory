<?php

namespace App\Http\Controllers\Api\V1\VendorReturn;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorReturn\CreateVendorReturnRequest;
use App\Http\Requests\VendorReturn\UpdateVendorReturnRequest;
use App\Http\Traits\ApiResponse;
use App\Services\VendorReturn\VendorReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VendorReturnService $vendorReturnService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $returns = $this->vendorReturnService->list($companyId, $filters);

        return $this->success($returns);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorReturnService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateVendorReturnRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorReturnService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Vendor return created successfully', 201);
    }

    public function update(UpdateVendorReturnRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorReturnService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Vendor return updated successfully');
    }

    public function updateStatus(UpdateVendorReturnRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorReturnService->updateStatus($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Vendor return status updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->vendorReturnService->delete($id, $companyId);

        return $this->success(['message' => 'Vendor return deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $stats = $this->vendorReturnService->getStats($companyId);

        return $this->success($stats);
    }

    public function getByVendor(Request $request, int $vendorId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $returns = $this->vendorReturnService->getByVendor($vendorId, $companyId, $filters);

        return $this->success($returns);
    }
}
