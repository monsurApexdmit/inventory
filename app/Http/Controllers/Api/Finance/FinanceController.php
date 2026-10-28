<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceListRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Finance\FinanceInventoryService;
use App\Services\Finance\FinanceObligationService;
use App\Services\Finance\FinanceOverviewService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Overview, inventory valuation / ledger / COGS, payouts, receivables and payments received. */
class FinanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FinanceOverviewService $overview,
        private readonly FinanceInventoryService $inventory,
        private readonly FinanceObligationService $obligations,
        private readonly \App\Services\Finance\FinancePartyService $parties,
    ) {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    private function paged(array $res): JsonResponse
    {
        return $this->success($res['data'], 'Success', 200, $res['meta']);
    }

    public function overview(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->overview->overview($this->companyId($request), $request->filters()));
    }

    // ─── Inventory ──────────────────────────────────────────────────────────

    public function valuation(FinanceListRequest $request): Response
    {
        $cid = $this->companyId($request);
        if ($request->wantsCsv()) {
            return Csv::download('stock-valuation-' . now()->format('Y-m-d') . '.csv', [
                'Product' => 'name', 'SKU' => 'sku', 'Category' => 'categoryName', 'Units' => 'units',
                'Unit cost' => 'unitCost', 'Unit price' => 'unitPrice', 'Cost value' => 'costValue',
                'Retail value' => 'retailValue', 'Potential profit' => 'potentialProfit',
            ], $this->inventory->valuation($cid, $request->filters(), true)['csv']);
        }

        return $this->paged($this->inventory->valuation($cid, $request->filters()));
    }

    public function inventoryLedger(FinanceListRequest $request): Response
    {
        $cid = $this->companyId($request);
        if ($request->wantsCsv()) {
            return Csv::download('inventory-ledger-' . now()->format('Y-m-d') . '.csv', [
                'Date' => 'date', 'Product' => 'productName', 'Variant' => 'variantName', 'SKU' => 'sku', 'Location' => 'locationName',
                'Type' => 'typeLabel', 'Qty in' => 'qtyIn', 'Qty out' => 'qtyOut', 'Unit cost' => 'unitCost', 'Value' => 'value',
                'Reference' => 'reference.label', 'Reason' => 'reason', 'Running qty' => 'runningQty', 'By' => 'createdBy',
            ], $this->inventory->ledger($cid, $request->filters(), true)['csv']);
        }

        return $this->paged($this->inventory->ledger($cid, $request->filters()));
    }

    public function cogs(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->inventory->cogs($this->companyId($request), $request->filters()));
    }

    // ─── Payouts ────────────────────────────────────────────────────────────

    public function payouts(FinanceListRequest $request): Response
    {
        $cid = $this->companyId($request);
        if ($request->wantsCsv()) {
            return Csv::download('payouts-' . now()->format('Y-m-d') . '.csv', [
                'Date' => 'date', 'Type' => 'typeLabel', 'Payee' => 'payee', 'Amount' => 'amount', 'Account' => 'accountName',
                'Method' => 'method', 'Reference' => 'reference', 'Note' => 'note', 'Id' => 'id',
            ], $this->obligations->payouts($cid, $request->filters(), true)['csv']);
        }

        return $this->paged($this->obligations->payouts($cid, $request->filters()));
    }

    public function payoutsSummary(Request $request): JsonResponse
    {
        return $this->success($this->obligations->payablesSummary($this->companyId($request)));
    }

    // ─── Receivables ────────────────────────────────────────────────────────

    public function receivables(FinanceListRequest $request): JsonResponse
    {
        return $this->paged($this->obligations->receivables($this->companyId($request), $request->filters()));
    }

    public function receivablesSummary(Request $request): JsonResponse
    {
        $cid = $this->companyId($request);

        return $this->success($this->obligations->receivablesSummary($cid) + ['suppliers' => $this->parties->supplierSummary($cid)]);
    }

    // ─── Payments received ──────────────────────────────────────────────────

    public function payments(FinanceListRequest $request): Response
    {
        $cid = $this->companyId($request);
        if ($request->wantsCsv()) {
            return Csv::download('payments-' . now()->format('Y-m-d') . '.csv', [
                'Date' => 'date', 'Type' => 'sourceLabel', 'Gross' => 'gross', 'Fee' => 'fee', 'Net' => 'net', 'Currency' => 'currency',
                'Account' => 'accountName', 'Method' => 'method', 'Channel' => 'channel', 'Customer' => 'party', 'Reference' => 'reference', 'Note' => 'note', 'Id' => 'id',
            ], $this->obligations->payments($cid, $request->filters(), true)['csv']);
        }

        return $this->paged($this->obligations->payments($cid, $request->filters()));
    }

    public function unpaidSales(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->obligations->unpaidSales($this->companyId($request), $request->filters()));
    }
}
