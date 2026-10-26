<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Tailor\TailorFabricOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TailorFabricOverviewController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TailorFabricOverviewService $svc) {}

    private function companyId(Request $r): int
    {
        return (int) $r->attributes->get('auth_company_id');
    }

    public function overview(Request $request): JsonResponse
    {
        return $this->success($this->svc->overview($this->companyId($request), $request->query()), 'Fabrics retrieved');
    }

    public function restock(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'quantity'  => 'required|numeric|gt:0',
            'vendor_id' => 'nullable|integer',
            'note'      => 'nullable|string|max:255',
        ]);
        $fabric = $this->svc->restock($this->companyId($request), $id, (float) $v['quantity'], $v['vendor_id'] ?? null, $v['note'] ?? null);
        return $this->success($fabric, 'Stock added');
    }

    public function movements(Request $request, int $id): JsonResponse
    {
        return $this->success($this->svc->movements($this->companyId($request), $id, (int) $request->query('limit', 50)), 'Movements retrieved');
    }
}
