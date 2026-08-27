<?php

namespace App\Http\Controllers\Api\V1\StockAdjustment;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustment\CreateAdjustmentRequest;
use App\Http\Traits\ApiResponse;
use App\Services\StockAdjustment\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockAdjustmentService $service)
    {
    }

    /** GET /adjustments — list past stock adjustments */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->service->list($companyId, $request->all());

        return $this->success($result['data'], 'Adjustments retrieved successfully', 200, [
            'total'        => $result['total'],
            'per_page'     => $result['per_page'],
            'current_page' => $result['current_page'],
            'last_page'    => $result['last_page'],
        ]);
    }

    /** POST /adjustments — apply a stock adjustment */
    public function store(CreateAdjustmentRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->service->create($companyId, $request->validated());

        return $this->success($result, 'Stock adjusted successfully', 201);
    }
}
