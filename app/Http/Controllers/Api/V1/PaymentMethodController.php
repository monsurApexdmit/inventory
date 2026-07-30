<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Setting\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PaymentMethodService $service)
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
            'name'         => 'required|string|max:255',
            'description'  => 'nullable|string|max:500',
            'icon'         => 'nullable|string|max:50',
            'gateway_type' => 'nullable|string|in:cod,manual,sslcommerz,portwallet,stripe,paypal,bkash,nagad',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer|min:0',
        ]);

        return $this->success($this->service->create($companyId, $data), 'Payment method created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'description'  => 'nullable|string|max:500',
            'icon'         => 'nullable|string|max:50',
            'gateway_type' => 'nullable|string|in:cod,manual,sslcommerz,portwallet,stripe,paypal,bkash,nagad',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer|min:0',
        ]);

        return $this->success($this->service->update($id, $companyId, $data), 'Payment method updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->service->delete($id, $companyId);

        return $this->success(null, 'Payment method deleted');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->service->toggle($id, $companyId), 'Status updated');
    }
}
