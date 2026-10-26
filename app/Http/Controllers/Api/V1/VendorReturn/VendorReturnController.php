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
        $result = $this->vendorReturnService->list($companyId, $request->query());

        return $this->success($result['data'], 'Vendor returns retrieved successfully', 200, [
            'total'        => $result['total'],
            'per_page'     => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page'    => $result['last_page'],
            'counts'       => $result['counts'],
            'stats'        => $result['stats'],
        ]);
    }

    public function returnableItems(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->vendorReturnService->returnableItems($companyId, $request->query()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->vendorReturnService->getFull($id, $companyId));
    }

    public function store(CreateVendorReturnRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->vendorReturnService->create($companyId, $request->validated());

        return $this->success($result, 'Vendor return created successfully', 201);
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
        $result = $this->vendorReturnService->updateStatus($id, $companyId, $request->validated());

        return $this->success($result, 'Vendor return status updated successfully');
    }

    public function addAttachments(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,gif,pdf|max:5120',
        ]);
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->vendorReturnService->addAttachments($id, $companyId, $request->file('files')), 'Photos uploaded');
    }

    public function removeAttachment(Request $request, int $id, int $attachmentId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->vendorReturnService->removeAttachment($id, $attachmentId, $companyId), 'Attachment removed');
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
