<?php

namespace App\Http\Controllers\Api\Attribute;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\BulkDeleteAttributeRequest;
use App\Http\Requests\Attribute\CreateAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Attribute\AttributeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AttributeService $attributeService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        $result = $this->attributeService->list($companyId, $filters);
        // meta is already inside data from pagination, just return as-is
        return $this->success($result);
    }

    public function simple(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->attributeService->simple($companyId));
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->attributeService->stats($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->attributeService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateAttributeRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->attributeService->create($companyId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Attribute created successfully',
            201
        );
    }

    public function update(UpdateAttributeRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->attributeService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->attributeService->toggleStatus($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->attributeService->delete($id, $companyId);

        return $this->success(['message' => 'Attribute deleted successfully']);
    }

    public function bulkDelete(BulkDeleteAttributeRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $deleted = $this->attributeService->bulkDelete($request->validated()['ids'], $companyId);

        return $this->success([
            'message' => 'Attributes deleted successfully',
            'deleted' => $deleted,
        ]);
    }
}
