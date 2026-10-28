<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceListRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Finance\FinanceCloseService;
use App\Services\Finance\FinanceContext;
use App\Services\Finance\FinanceExpenseService;
use App\Services\Finance\FinanceFeeService;
use App\Services\Finance\FinanceHealthService;
use App\Services\Finance\FinanceMetricsService;
use App\Services\Finance\FinancePartyService;
use App\Services\Finance\FinanceStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Finance v2 endpoints: metrics, fees, month close, expense voids, stock move-in / backfill / reconciliation, parties, health. */
class FinanceV2Controller extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FinanceMetricsService $metrics,
        private readonly FinanceFeeService $fees,
        private readonly FinanceCloseService $closes,
        private readonly FinanceExpenseService $expenses,
        private readonly FinanceStockService $stock,
        private readonly FinancePartyService $parties,
        private readonly FinanceHealthService $health,
    ) {
    }

    private function cid(Request $r): int
    {
        return (int) $r->attributes->get('auth_company_id');
    }

    private function uid(Request $r): ?int
    {
        $id = $r->attributes->get('auth_user_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function paged(array $res): JsonResponse
    {
        return $this->success($res['data'], 'Success', 200, $res['meta']);
    }

    // ─── Metrics ────────────────────────────────────────────────────────────

    public function metrics(FinanceListRequest $r): JsonResponse
    {
        $cid = $this->cid($r);
        $period = FinanceContext::period($cid, $r->filters(), 'this_month');
        $channel = FinanceContext::channel($r->filters());

        return $this->success(['context' => FinanceContext::context($cid, $period, $channel), 'channelScope' => 'sales_only'] + $this->metrics->metrics($cid, $period, $channel));
    }

    public function productsWithoutCost(FinanceListRequest $r): JsonResponse
    {
        return $this->paged($this->metrics->productsWithoutCost($this->cid($r), $r->filters()));
    }

    // ─── Fees ───────────────────────────────────────────────────────────────

    public function fees(Request $r): JsonResponse
    {
        return $this->success($this->fees->get($this->cid($r)));
    }

    public function updateFees(Request $r): JsonResponse
    {
        return $this->success($this->fees->update($this->cid($r), $r->input('fees')), 'Payment fees saved');
    }

    // ─── Month close ────────────────────────────────────────────────────────

    public function closes(Request $r): JsonResponse
    {
        return $this->success($this->closes->overview($this->cid($r)));
    }

    public function closeMonth(Request $r, string $month): JsonResponse
    {
        return $this->success($this->closes->get($this->cid($r), $month));
    }

    public function close(Request $r): JsonResponse
    {
        $res = $this->closes->close($this->cid($r), $r->input('month'), $r->input('note'), $this->uid($r));

        return $this->success($res, $res['label'] . ' closed', 201);
    }

    public function reopen(Request $r, string $month): JsonResponse
    {
        $res = $this->closes->reopen($this->cid($r), $month, $r->input('reason'), $this->uid($r));

        return $this->success($res, $res['label'] . ' reopened');
    }

    // ─── Expenses ───────────────────────────────────────────────────────────

    public function voidExpense(Request $r, int $id): JsonResponse
    {
        return $this->success($this->expenses->void($this->cid($r), $id, $r->input('reason'), $r->input('date'), $this->uid($r)), 'Expense voided');
    }

    public function expenseActivity(Request $r, int $id): JsonResponse
    {
        return $this->success($this->expenses->activity($this->cid($r), $id));
    }

    // ─── Inventory ──────────────────────────────────────────────────────────

    public function openingStock(FinanceListRequest $r): JsonResponse
    {
        return $this->paged($this->stock->openingPreview($this->cid($r), $r->filters()));
    }

    public function postOpeningStock(Request $r): JsonResponse
    {
        $res = $this->stock->postOpening($this->cid($r), $r->all(), $this->uid($r));

        return $this->success($res, $res['posted'] > 0 ? 'Opening stock moved in' : 'Nothing to move in', $res['posted'] > 0 ? 201 : 200);
    }

    public function missingSaleMovements(Request $r): JsonResponse
    {
        return $this->success($this->stock->missingSummary($this->cid($r)));
    }

    public function backfillSaleMovements(Request $r): JsonResponse
    {
        $dry = filter_var($r->input('dryRun', false), FILTER_VALIDATE_BOOLEAN);
        $res = $this->stock->backfillSaleMovements($this->cid($r), $dry);

        return $this->success($res, $dry ? 'Dry run: nothing was written' : 'Missing sale movements created');
    }

    public function stockReconciliation(Request $r): JsonResponse
    {
        return $this->success($this->stock->reconciliation($this->cid($r)));
    }

    // ─── Parties ────────────────────────────────────────────────────────────

    public function payable(FinanceListRequest $r): JsonResponse
    {
        return $this->success($this->parties->payable($this->cid($r), $r->filters()));
    }

    public function payableOne(Request $r, string $type, int $id): JsonResponse
    {
        return $this->success($this->parties->payableOne($this->cid($r), $type, $id));
    }

    public function supplierReceivables(FinanceListRequest $r): JsonResponse
    {
        return $this->success($this->parties->supplierReceivables($this->cid($r), $r->filters()));
    }

    public function receiveFromSupplier(Request $r, int $vendorId): JsonResponse
    {
        return $this->success($this->parties->receiveFromSupplier($this->cid($r), $vendorId, $r->all(), $this->uid($r)), 'Payment received from supplier', 201);
    }

    public function customerReceivables(FinanceListRequest $r): JsonResponse
    {
        return $this->paged($this->parties->customers($this->cid($r), $r->filters()));
    }

    public function paymentsSummary(FinanceListRequest $r): JsonResponse
    {
        return $this->success($this->parties->paymentsSummary($this->cid($r), $r->filters()));
    }

    // ─── Health ─────────────────────────────────────────────────────────────

    public function health(FinanceListRequest $r): JsonResponse
    {
        return $this->success($this->health->run($this->cid($r), $r->filters()));
    }
}
