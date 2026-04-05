<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\CreateShippingAddressRequest;
use App\Http\Requests\Shipping\UpdateShippingAddressRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Shipping\ShippingAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingAddressController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShippingAddressService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $addresses = $this->service->list($companyId, $request->query());
        return $this->success($addresses);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->get($id, $companyId);
        return $this->success($dto->toArray());
    }

    public function store(CreateShippingAddressRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->create($companyId, $request->validated());
        return $this->success($dto->toArray(), 'Shipping address created successfully', 201);
    }

    public function update(UpdateShippingAddressRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->update($id, $companyId, $request->validated());
        return $this->success($dto->toArray(), 'Shipping address updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->service->delete($id, $companyId);
        return $this->success(['message' => 'Shipping address deleted successfully']);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->setDefault($id, $companyId);
        return $this->success($dto->toArray(), 'Address set as default successfully');
    }
}
