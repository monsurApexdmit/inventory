<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Report\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $service)
    {
    }

    /** GET /reports/{type}?from=Y-m-d&to=Y-m-d  (type: sales|purchases|stock|dues|pnl) */
    public function show(Request $request, string $type): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }
        if (!in_array($type, ReportService::TYPES, true)) {
            return $this->error('Unknown report type', 404);
        }

        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        try {
            return $this->success($this->service->report($companyId, $type, $filters), 'Report retrieved');
        } catch (\Throwable $e) {
            \Log::error('Report failed', ['type' => $type, 'message' => $e->getMessage()]);

            return $this->error('Failed to load report', 500);
        }
    }
}
