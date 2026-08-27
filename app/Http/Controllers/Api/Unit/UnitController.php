<?php

namespace App\Http\Controllers\Api\Unit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Unit\CreateUnitRequest;
use App\Http\Requests\Unit\UpdateUnitRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Unit\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UnitService $unitService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->unitService->list($companyId, $request->all()));
    }

    public function simple(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->unitService->simple($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->unitService->get($id, $companyId));
    }

    public function store(CreateUnitRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->unitService->create($companyId, $request->validated()), 'Unit created successfully', 201);
    }

    public function update(UpdateUnitRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->unitService->update($id, $companyId, $request->validated()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->unitService->delete($id, $companyId);
        return $this->success(['message' => 'Unit deleted successfully']);
    }
}
