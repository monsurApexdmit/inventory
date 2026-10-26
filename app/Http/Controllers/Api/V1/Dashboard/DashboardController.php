<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    private const SECTIONS = [
        'alerts', 'kpis', 'revenue', 'pipeline', 'stock-health', 'reorder',
        'top-products', 'channels', 'obligations', 'recent-orders', 'activity',
    ];

    public function __construct(private readonly DashboardService $service)
    {
    }

    /**
     * GET /dashboard/{section}?from=Y-m-d&to=Y-m-d&location_id=
     * One endpoint per dashboard section so sections load and fail independently.
     */
    public function section(Request $request, string $section): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        if (!in_array($section, self::SECTIONS, true)) {
            return $this->error('Unknown dashboard section', 404);
        }

        $filters = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'location_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:30',
        ]);

        $method = match ($section) {
            'stock-health' => 'stockHealth',
            'top-products' => 'topProducts',
            'recent-orders' => 'recentOrders',
            default => $section,
        };

        try {
            return $this->success($this->service->{$method}($companyId, $filters), 'Dashboard section retrieved');
        } catch (\Throwable $e) {
            \Log::error('Dashboard section failed', ['section' => $section, 'message' => $e->getMessage()]);

            return $this->error('Failed to load dashboard section', 500);
        }
    }
}
