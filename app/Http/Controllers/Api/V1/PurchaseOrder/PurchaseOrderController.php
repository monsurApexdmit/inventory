<?php

namespace App\Http\Controllers\Api\V1\PurchaseOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrder\ReceivePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderStatusRequest;
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

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->create($companyId, $request->validated());
        return $this->success($dto->toArray(), 'Purchase order created', 201);
    }

    public function update(UpdatePurchaseOrderRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->update($id, $companyId, $request->validated());
        return $this->success($dto->toArray(), 'Purchase order updated');
    }

    public function receive(ReceivePurchaseOrderRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->receive($id, $companyId, $request->validated());
        return $this->success($dto->toArray(), 'Stock received successfully');
    }

    public function updateStatus(UpdatePurchaseOrderStatusRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->updateStatus($id, $companyId, $request->validated()['status']);
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

    /**
     * GET /dues/vendors
     * Retrieve vendor dues (purchase orders with an outstanding due amount).
     */
    public function dues(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $filters = $request->query();
            $result = $this->service->getVendorDues($companyId, $filters);

            return $this->success($result['data'], 'Vendor dues retrieved successfully', 200, [
                'summary' => $result['summary'],
                'pagination' => [
                    'page' => $result['current_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'last_page' => $result['last_page'],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Vendor dues fetch failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve vendor dues', 500);
        }
    }
}
