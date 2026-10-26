<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Tailor\TailorDashboardService;
use App\Services\Tailor\TailorReportService;
use App\Services\Tailor\TailorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tailor shop dashboard + UI reports (GET /tailor/dashboard, GET /tailor/reports). */
class TailorInsightsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TailorDashboardService $dashboard,
        private readonly TailorReportService $reports,
        private readonly TailorService $tailor,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $cid = (int) $request->attributes->get('auth_company_id');
            $data = $this->dashboard->dashboard($cid);
            // Keep the legacy flat stats keys working for older clients.
            try {
                $legacy = $this->tailor->dashboardStats($cid);
                $legacy['recent_orders'] = collect($legacy['recent_orders'])->all();
                $data += $legacy;
            } catch (\Throwable $e) {
            }

            return $this->success($data, 'Dashboard retrieved');
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Failed to retrieve dashboard', 500);
        }
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'nullable|in:orders,fabrics,dorjis',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        try {
            $cid = (int) $request->attributes->get('auth_company_id');

            return $this->success($this->reports->report($cid, $data['type'] ?? 'orders', $data), 'Report retrieved');
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Failed to build report', 500);
        }
    }
}
