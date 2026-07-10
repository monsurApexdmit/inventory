<?php

namespace App\Http\Controllers\Api\V1\StockTransfer;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockTransfer\CreateTransferRequest;
use App\Http\Traits\ApiResponse;
use App\Services\StockTransfer\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockTransferService $stockTransferService)
    {
    }

    /**
     * GET /transfers/
     * List all stock transfers for the company
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        $result = $this->stockTransferService->list($companyId, $filters);

        return $this->success($result['data'], 'Transfers retrieved successfully', 200, [
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page' => $result['last_page'],
        ]);
    }

    /**
     * GET /transfers/products-by-location/:location_id
     * Get available products in a specific location
     */
    public function getProductsByLocation(int $locationId, Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        $result = $this->stockTransferService->getProductsByLocation($companyId, $locationId, $filters);

        return $this->success($result['data'], 'Products retrieved successfully', 200, [
            'total' => $result['total'],
            'per_page' => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page' => $result['last_page'],
        ]);
    }

    /**
     * POST /transfers/
     * Create a new stock transfer
     */
    public function store(CreateTransferRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->stockTransferService->createTransfer($companyId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Transfer created successfully',
            201
        );
    }

    /**
     * PUT /transfers/:id/cancel
     * Cancel an existing transfer
     */
    public function cancelTransfer(int $id, Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->stockTransferService->cancelTransfer($companyId, $id);

        return $this->success(
            $dto->toArray(),
            'Transfer cancelled successfully'
        );
    }
}
