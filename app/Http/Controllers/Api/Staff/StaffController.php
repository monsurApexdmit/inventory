<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StaffService $staffService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        return $this->success($this->staffService->list($companyId, $filters));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffService->create($companyId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Staff member created successfully',
            201
        );
    }

    public function update(UpdateStaffRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function destroy(Request $request, int $id): Response
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->staffService->delete($id, $companyId);

        return response()->noContent();
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->staffService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Staff stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
