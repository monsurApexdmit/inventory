<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Shipping\ShippingMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingMethodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShippingMethodService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->service->list($companyId));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'description'    => 'nullable|string|max:500',
            'price'          => 'required|numeric|min:0',
            'estimated_days' => 'nullable|string|max:100',
            'icon'           => 'nullable|string|max:50',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        return $this->success($this->service->create($companyId, $data), 'Shipping method created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'description'    => 'nullable|string|max:500',
            'price'          => 'sometimes|numeric|min:0',
            'estimated_days' => 'nullable|string|max:100',
            'icon'           => 'nullable|string|max:50',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer|min:0',
        ]);

        return $this->success($this->service->update($id, $companyId, $data), 'Shipping method updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->service->delete($id, $companyId);

        return $this->success(null, 'Shipping method deleted');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->service->toggle($id, $companyId), 'Status updated');
    }
}
