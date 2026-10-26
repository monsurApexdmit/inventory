<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Inventory\InventoryOverviewService;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InventoryService $inventoryService,
        private readonly InventoryOverviewService $overviewService)
    {
    }

    /**
     * GET /inventory/
     * Get paginated inventory with per-location breakdown
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            // Get filters from query parameters
            // Support both 'per_page' (standard) and 'limit' (legacy) parameters
            $perPage = $request->query('per_page') ?? $request->query('limit') ?? 10;

            $filters = [
                'page' => $request->query('page', 1),
                'limit' => $perPage,
                'search' => $request->query('search'),
                'location_id' => $request->query('location_id'),
            ];

            $result = $this->inventoryService->getInventory($companyId, $filters);

            return $this->success($result['data'], 'Inventory retrieved successfully', 200, [
                'total' => $result['total'],
                'page' => $result['page'],
                'limit' => $result['limit'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Inventory retrieval failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->error('Failed to retrieve inventory', 500);
        }
    }

    /** GET /inventory/overview — SKU-level stock overview with health, stats and counts */
    public function overview(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        $result = $this->overviewService->overview($companyId, $request->query());

        return $this->success($result, 'Inventory overview retrieved successfully');
    }
}
