<?php

namespace App\Http\Controllers\Api\V1\PurchaseOrder;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\PurchaseOrder\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->list($companyId, $request->query()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->get($id, $companyId)->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'vendorId'     => 'required|integer',
            'locationId'   => 'nullable|integer',
            'expectedDate' => 'nullable|date',
            'notes'        => 'nullable|string',
            'items'        => 'required|array|min:1',
            'items.*.productId'       => 'required|integer',
            'items.*.variantId'       => 'nullable|integer',
            'items.*.quantityOrdered' => 'required|integer|min:1',
            'items.*.unitCost'        => 'required|numeric|min:0',
        ]);

        $dto = $this->service->create($companyId, $data);
        return $this->success($dto->toArray(), 'Purchase order created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'vendorId'     => 'nullable|integer',
            'locationId'   => 'nullable|integer',
            'status'       => 'nullable|in:draft,sent,cancelled',
            'expectedDate' => 'nullable|date',
            'notes'        => 'nullable|string',
            'items'        => 'nullable|array|min:1',
            'items.*.productId'        => 'required_with:items|integer',
            'items.*.variantId'        => 'nullable|integer',
            'items.*.quantityOrdered'  => 'required_with:items|integer|min:1',
            'items.*.quantityReceived' => 'nullable|integer|min:0',
            'items.*.unitCost'         => 'required_with:items|numeric|min:0',
        ]);

        $dto = $this->service->update($id, $companyId, $data);
        return $this->success($dto->toArray(), 'Purchase order updated');
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'items'                      => 'required|array|min:1',
            'items.*.itemId'             => 'required|integer',
            'items.*.quantityReceiving'  => 'required|integer|min:1',
        ]);

        $dto = $this->service->receive($id, $companyId, $data);
        return $this->success($dto->toArray(), 'Stock received successfully');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate(['status' => 'required|in:draft,sent,cancelled']);
        $dto = $this->service->updateStatus($id, $companyId, $data['status']);
        return $this->success($dto->toArray(), 'Status updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->service->delete($id, $companyId);
        return $this->success(null, 'Purchase order deleted');
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->getStats($companyId));
    }
}
