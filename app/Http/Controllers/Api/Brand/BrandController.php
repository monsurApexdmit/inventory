<?php

namespace App\Http\Controllers\Api\Brand;

use App\Http\Controllers\Controller;
use App\Http\Requests\Brand\CreateBrandRequest;
use App\Http\Requests\Brand\UpdateBrandRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Brand\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BrandService $brandService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->brandService->list($companyId, $request->all()));
    }

    public function simple(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->brandService->simple($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->brandService->get($id, $companyId));
    }

    public function store(CreateBrandRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->brandService->create($companyId, $request->validated()), 'Brand created successfully', 201);
    }

    public function update(UpdateBrandRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->brandService->update($id, $companyId, $request->validated()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->brandService->delete($id, $companyId);
        return $this->success(['message' => 'Brand deleted successfully']);
    }
}
