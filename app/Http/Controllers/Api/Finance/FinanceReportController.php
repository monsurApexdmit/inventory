<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceListRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Finance\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinanceReportService $reports)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function pnl(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->reports->pnl($this->companyId($request), $request->filters()));
    }

    public function cashflow(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->reports->cashflow($this->companyId($request), $request->filters()));
    }

    public function statement(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->reports->statement($this->companyId($request), $request->filters()));
    }

    public function tax(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->reports->tax($this->companyId($request), $request->filters()));
    }

    public function aging(Request $request): JsonResponse
    {
        return $this->success($this->reports->aging($this->companyId($request)));
    }
}
