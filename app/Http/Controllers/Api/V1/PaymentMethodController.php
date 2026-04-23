<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $methods = PaymentMethod::where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn($m) => $this->format($m));

        return $this->success($methods);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:50',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
        ]);

        $method = PaymentMethod::create(array_merge($data, ['company_id' => $companyId]));

        return $this->success($this->format($method), 'Payment method created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $method = PaymentMethod::where('company_id', $companyId)->findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:50',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
        ]);

        $method->update($data);

        return $this->success($this->format($method->fresh()), 'Payment method updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $method = PaymentMethod::where('company_id', $companyId)->findOrFail($id);
        $method->delete();

        return $this->success(null, 'Payment method deleted');
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $method = PaymentMethod::where('company_id', $companyId)->findOrFail($id);
        $method->update(['is_active' => !$method->is_active]);

        return $this->success($this->format($method->fresh()), 'Status updated');
    }

    private function format(PaymentMethod $m): array
    {
        return [
            'id'          => $m->id,
            'name'        => $m->name,
            'description' => $m->description,
            'icon'        => $m->icon,
            'isActive'    => $m->is_active,
            'sortOrder'   => $m->sort_order,
        ];
    }
}
