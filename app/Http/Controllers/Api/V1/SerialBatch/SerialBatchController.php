<?php

namespace App\Http\Controllers\Api\V1\SerialBatch;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\SerialBatch\SerialBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SerialBatchController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SerialBatchService $service) {}

    // ─── Serials ────────────────────────────────────────────────────────────

    public function indexSerials(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->listSerials($companyId, $request->query()));
    }

    public function showSerial(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->getSerial($id, $companyId)->toArray());
    }

    public function storeSerials(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'productId'   => 'required|integer|min:1',
            'variantId'   => 'nullable|integer|min:1',
            'locationId'  => 'nullable|integer|min:1',
            'serials'     => 'required|array|min:1',
            'serials.*.serialNumber'         => 'required|string|max:100',
            'serials.*.purchaseOrderNumber'  => 'nullable|string|max:100',
            'serials.*.receivedDate'         => 'nullable|date',
            'serials.*.notes'                => 'nullable|string',
        ]);

        $created = $this->service->createSerials($companyId, $data);
        return $this->success($created, 'Serials added successfully', 201);
    }

    public function updateSerial(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'locationId'          => 'nullable|integer|min:1',
            'status'              => 'nullable|in:available,sold,returned,damaged',
            'purchaseOrderNumber' => 'nullable|string|max:100',
            'receivedDate'        => 'nullable|date',
            'notes'               => 'nullable|string',
        ]);

        return $this->success($this->service->updateSerial($id, $companyId, $data)->toArray());
    }

    public function destroySerial(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->service->deleteSerial($id, $companyId);
        return $this->success(null, 'Serial deleted');
    }

    // ─── Batches ────────────────────────────────────────────────────────────

    public function indexBatches(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->listBatches($companyId, $request->query()));
    }

    public function showBatch(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->getBatch($id, $companyId)->toArray());
    }

    public function storeBatch(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'productId'          => 'required|integer|min:1',
            'variantId'          => 'nullable|integer|min:1',
            'locationId'         => 'nullable|integer|min:1',
            'batchNumber'        => 'required|string|max:100',
            'quantityReceived'   => 'required|integer|min:1',
            'manufactureDate'    => 'nullable|date',
            'expiryDate'         => 'nullable|date|after:today',
            'purchaseOrderNumber'=> 'nullable|string|max:100',
            'receivedDate'       => 'nullable|date',
            'notes'              => 'nullable|string',
        ]);

        return $this->success($this->service->createBatch($companyId, $data)->toArray(), 'Batch created', 201);
    }

    public function updateBatch(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'locationId'          => 'nullable|integer|min:1',
            'quantityRemaining'   => 'nullable|integer|min:0',
            'manufactureDate'     => 'nullable|date',
            'expiryDate'          => 'nullable|date',
            'purchaseOrderNumber' => 'nullable|string|max:100',
            'receivedDate'        => 'nullable|date',
            'notes'               => 'nullable|string',
        ]);

        return $this->success($this->service->updateBatch($id, $companyId, $data)->toArray());
    }

    public function destroyBatch(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->service->deleteBatch($id, $companyId);
        return $this->success(null, 'Batch deleted');
    }

    // ─── Movements ──────────────────────────────────────────────────────────

    public function indexMovements(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->listMovements($companyId, $request->query()));
    }

    // ─── Stats ──────────────────────────────────────────────────────────────

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->getStats($companyId));
    }
}
