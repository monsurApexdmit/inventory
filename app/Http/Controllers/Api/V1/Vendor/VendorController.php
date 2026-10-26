<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\CreateVendorRequest;
use App\Http\Requests\Vendor\UpdateVendorRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Vendor\VendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly VendorService $vendorService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->query();

        $vendors = $this->vendorService->list($companyId, $filters);

        return $this->success($vendors);
    }

    public function overview(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:all,Active,Inactive,Blocked,owe',
            'sort' => 'nullable|in:purchased,owed,name,recent',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        return $this->success($this->vendorService->overview($companyId, $filters));
    }

    public function profile(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->vendorService->profile($id, $companyId));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'method' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:1000',
            'date' => 'nullable|date',
        ]);
        $recordedBy = $request->attributes->get('auth_user_id');

        $profile = $this->vendorService->recordPayment($id, $companyId, $data, $recordedBy ? (int) $recordedBy : null);

        return $this->success($profile, 'Payment recorded successfully', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateVendorRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorService->create($companyId, $request->validated());

        return $this->success($dto->toArray(), 'Vendor created successfully', 201);
    }

    public function update(UpdateVendorRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->vendorService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray(), 'Vendor updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->vendorService->delete($id, $companyId);

        return $this->success(['message' => 'Vendor deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->vendorService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Vendor stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
