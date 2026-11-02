<?php

namespace App\Services\Finance;

use App\Support\Money;

/** Finance overview: period KPIs with previous-period compare, charts, recent lines and alerts. */
class FinanceOverviewService
{
    public function __construct(
        private readonly FinanceReportService $reports,
        private readonly FinanceAccountService $accounts,
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceObligationService $obligations,
        private readonly FinanceMetricsService $metrics,
        private readonly FinancePartyService $parties,
        private readonly FinanceStockService $stock,
        private readonly FinanceCloseService $closes,
    ) {
    }

    private static function kpi(float $value, ?float $previous): array
    {
        $chg = ($previous === null || abs($previous) < 0.005) ? null : round(($value - $previous) / abs($previous) * 100, 2);

        return ['value' => round($value, 2), 'previous' => $previous === null ? null : round($previous, 2), 'changePct' => $chg];
    }

    public function overview(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $from = $period->fromDate();
        $to = $period->toDate();
        $prev = $period->previous();

        $pnl = $this->metrics->pnl($cid, $from, $to, $channel);
        $pPnl = $prev ? $this->metrics->pnl($cid, $prev->fromDate(), $prev->toDate(), $channel) : null;
        $cash = $this->metrics->moneyInOut($cid, $from, $to, $channel);
        $pCash = $prev ? $this->metrics->moneyInOut($cid, $prev->fromDate(), $prev->toDate(), $channel) : null;
        $metrics = $this->metrics->metrics($cid, $period, $channel);
        $pOrders = $prev ? $this->metrics->orders($cid, $prev->fromDate(), $prev->toDate(), $channel) : null;
        $pRefunds = $prev ? $this->metrics->refunds($cid, $prev->fromDate(), $prev->toDate(), $prev, $channel) : null;

        $map = $this->accounts->map($cid);
        $balances = $this->accounts->balances($cid, $map, FinanceContext::todayDate($cid));
        $cashOnHand = 0.0;
        $accountBalances = [];
        foreach ($map->accounts as $id => $a) {
            if ($a->is_active) {
                $cashOnHand += $balances[$id];
            }
            $accountBalances[] = ['accountId' => $id, 'name' => $a->name, 'type' => $a->type, 'isActive' => (bool) $a->is_active, 'balance' => $balances[$id]];
        }

        $rec = $this->obligations->receivablesSummary($cid, 0);
        $pay = $this->obligations->payablesSummary($cid, 0);

        $kpis = [
            'revenue' => self::kpi($pnl['revenue'], $pPnl['revenue'] ?? null),
            'moneyIn' => self::kpi($cash['moneyIn'], $pCash['moneyIn'] ?? null),
            'moneyOut' => self::kpi($cash['moneyOut'], $pCash['moneyOut'] ?? null),
            'netCashFlow' => self::kpi($cash['net'], $pCash['net'] ?? null),
            'cogs' => self::kpi($pnl['cogs'], $pPnl['cogs'] ?? null),
            'grossProfit' => self::kpi($pnl['grossProfit'], $pPnl['grossProfit'] ?? null),
            'expenses' => self::kpi($pnl['expenses'], $pPnl['expenses'] ?? null),
            'netProfit' => self::kpi($pnl['netProfit'], $pPnl['netProfit'] ?? null),
            'cashOnHand' => self::kpi($cashOnHand, null),
            'receivables' => self::kpi($rec['total'], null),
            'payables' => self::kpi($pay['total'], null),
            'orderVolume' => self::kpi($metrics['orders']['volume'], $pOrders['volume'] ?? null),
            'paymentFees' => self::kpi($pnl['paymentFees'], $pPnl['paymentFees'] ?? null),
            'refunds' => self::kpi($metrics['refunds']['refunded'], $pRefunds['refunded'] ?? null),
        ];
        $supplierCredits = $this->parties->supplierSummary($cid);
        $ps = $metrics['productSales'];
        $stockAlert = $this->stockAlerts($cid);

        $exTotal = $pnl['expenses'];
        $byCat = array_map(fn ($c) => $c + ['pct' => $exTotal > 0 ? round($c['amount'] / $exTotal * 100, 1) : 0], $pnl['expensesByCategory']);

        $recentQ = $this->ledger->base($cid);
        if ($channel !== 'all') {
            $recentQ->where('l.channel', $channel);
        }
        $recent = $this->ledger->ordered($recentQ)->limit(10)->get()->map(fn ($r) => $this->ledger->mapRow($r, $map))->all();
        $closeStatus = $this->closes->status($cid);

        return [
            'context' => FinanceContext::context($cid, $period, $channel),
            'channelScope' => 'sales_only',
            'metrics' => $metrics,
            'storeCredit' => $metrics['storeCredit'] + ['href' => '/dashboard/customers'],
            'profitCoverage' => [
                'productSales' => $ps['total'], 'costed' => $ps['costed'], 'uncosted' => $ps['uncosted'], 'excluded' => $ps['excluded'],
                'costedPct' => $ps['costedPct'], 'productsWithoutCost' => $ps['productsWithoutCost'], 'setCostsHref' => '/dashboard/finance/inventory?tab=costs',
            ],
            'closeStatus' => $closeStatus,
            'period' => $period->toArray(),
            'currency' => FinanceContext::currency($cid),
            'kpis' => $kpis,
            'receivables' => [
                'customerDues' => $rec['customerDues']['excludingCod'],
                'codWithCouriers' => $rec['codWithCouriers']['total'],
                'tailorBalances' => $rec['tailorBalances']['total'],
                'total' => $rec['total'],
            ],
            'payables' => [
                'supplierDues' => $pay['supplierDues']['total'],
                'unpaidSalaries' => $pay['unpaidSalaries']['total'],
                'dorjiOwed' => $pay['dorjiOwed']['total'],
                'total' => $pay['total'],
                'supplierCredits' => $supplierCredits['theyOweYou'],
            ],
            'pnl' => ['otherIncome' => $pnl['otherIncome'], 'otherExpenses' => $pnl['otherExpenses'], 'paymentFees' => $pnl['paymentFees'], 'voidedExpenses' => $pnl['voidedExpenses']],
            'charts' => [
                'cashFlow' => $this->reports->series($cid, $period, $channel),
                'expensesByCategory' => $byCat,
                'accountBalances' => $accountBalances,
            ],
            'recentTransactions' => $recent,
            'alerts' => array_merge($this->alerts($cid, $rec, $pay, $accountBalances), $this->v2Alerts($cid, $stockAlert, $supplierCredits, $closeStatus)),
        ];
    }

    /** Opening stock waiting + stock value vs movement gap (cheap enough for the overview). */
    private function stockAlerts(int $cid): array
    {
        try {
            $open = $this->stock->openingCandidates($cid)['rows'];
            $rec = $this->stock->reconciliation($cid, 0);
        } catch (\Throwable) {
            return ['opening' => 0, 'openingNeedsCost' => 0, 'gap' => 0.0];
        }

        return [
            'opening' => count($open),
            'openingNeedsCost' => count(array_filter($open, fn ($r) => $r['needsCost'])),
            'gap' => $rec['difference'],
        ];
    }

    private function v2Alerts(int $cid, array $stock, array $supplier, array $close): array
    {
        $m = fn (float $v) => Money::format($cid, $v);
        $out = [];
        if ($stock['opening'] > 0) {
            $n = $stock['opening'];
            $msg = "Opening stock for {$n} " . ($n === 1 ? "product hasn't" : "products hasn't") . ' been moved in yet.';
            if ($stock['openingNeedsCost'] > 0) {
                $msg .= ' ' . $stock['openingNeedsCost'] . ' still ' . ($stock['openingNeedsCost'] === 1 ? 'needs a cost.' : 'need a cost.');
            }
            $out[] = ['type' => 'opening_stock', 'severity' => 'warning', 'title' => 'Move in opening stock', 'message' => $msg,
                'amount' => null, 'count' => $n, 'href' => '/dashboard/finance/inventory?tab=opening'];
        }
        if (abs($stock['gap']) >= 0.01) {
            $out[] = ['type' => 'stock_gap', 'severity' => 'info', 'title' => 'Stock value and movements differ',
                'message' => 'Stock value is ' . $m(abs($stock['gap'])) . ($stock['gap'] > 0 ? ' higher' : ' lower') . ' than the stock movements explain. Show the math in Inventory.',
                'amount' => $stock['gap'], 'count' => 1, 'href' => '/dashboard/finance/inventory?tab=reconciliation'];
        }
        if ($supplier['theyOweYou'] > 0) {
            $out[] = ['type' => 'supplier_owes_you', 'severity' => 'info', 'title' => 'Suppliers owe you',
                'message' => $m($supplier['theyOweYou']) . ' in supplier credits is not used yet. Collect it or offset it against the next payment.',
                'amount' => $supplier['theyOweYou'], 'count' => 1, 'href' => '/dashboard/finance/receivables?tab=suppliers'];
        }
        if ($close['suggestion']) {
            $out[] = ['type' => 'month_to_close', 'severity' => 'info', 'title' => $close['suggestion']['text'],
                'message' => $close['suggestion']['label'] . ' has ended and is still open. ' . $close['text'],
                'amount' => null, 'count' => 1, 'href' => '/dashboard/finance/reports?tab=close'];
        }

        return $out;
    }

    private function currencyCode(int $cid): string
    {
        $s = \App\Models\CompanySettings::where('company_id', $cid)->value('currency');

        return strtoupper((string) ($s ?: \App\Models\Company::where('id', $cid)->value('currency') ?: 'BDT'));
    }

    private function alerts(int $cid, array $rec, array $pay, array $balances): array
    {
        $m = fn (float $v) => Money::format($cid, $v);
        $out = [];
        $cd = $rec['customerDues'];
        if ($cd['overdue30Amount'] > 0) {
            $n = $cd['overdue30Parties'];
            $out[] = ['type' => 'overdue_receivables', 'severity' => 'warning', 'title' => 'Overdue customer dues',
                'message' => $m($cd['overdue30Amount']) . " owed by {$n} " . ($n === 1 ? 'customer' : 'customers') . ' for more than 30 days.',
                'amount' => $cd['overdue30Amount'], 'count' => $n, 'href' => '/dashboard/finance/receivables'];
        }
        $sd = $pay['supplierDues'];
        if ($sd['overdueAmount'] > 0) {
            $n = $sd['overdueParties'];
            $out[] = ['type' => 'overdue_payables', 'severity' => 'danger', 'title' => 'Overdue supplier bills',
                'message' => $m($sd['overdueAmount']) . " is past the expected date for {$n} " . ($n === 1 ? 'supplier' : 'suppliers') . '.',
                'amount' => $sd['overdueAmount'], 'count' => $n, 'href' => '/dashboard/finance/payouts'];
        }
        $us = $pay['unpaidSalaries'];
        if ($us['pastMonthsAmount'] > 0) {
            $n = $us['pastMonthsCount'];
            $out[] = ['type' => 'unpaid_salaries', 'severity' => 'warning', 'title' => 'Unpaid salaries',
                'message' => $m($us['pastMonthsAmount']) . " in {$n} " . ($n === 1 ? 'payslip' : 'payslips') . ' from past months is still unpaid.',
                'amount' => $us['pastMonthsAmount'], 'count' => $n, 'href' => '/dashboard/finance/payouts'];
        }
        $dj = $pay['dorjiOwed'];
        if ($dj['total'] > 0) {
            $n = $dj['count'];
            $out[] = ['type' => 'dorji_owed', 'severity' => 'info', 'title' => 'Dorji payouts due',
                'message' => $m($dj['total']) . " is owed to {$n} " . ($n === 1 ? 'dorji' : 'dorjis') . ' for completed work.',
                'amount' => $dj['total'], 'count' => $n, 'href' => '/dashboard/finance/payouts'];
        }
        $cod = $rec['codWithCouriers'];
        if ($cod['deliveredAmount'] > 0) {
            $n = $cod['deliveredCount'];
            $out[] = ['type' => 'cod_to_record', 'severity' => 'warning', 'title' => 'COD to record',
                'message' => $m($cod['deliveredAmount']) . " from {$n} delivered COD " . ($n === 1 ? 'order has' : 'orders have') . ' not been recorded as collected.',
                'amount' => $cod['deliveredAmount'], 'count' => $n, 'href' => '/dashboard/finance/receivables'];
        }
        foreach ($balances as $b) {
            if ($b['isActive'] && $b['balance'] < 0) {
                $out[] = ['type' => 'negative_balance', 'severity' => 'danger', 'title' => "{$b['name']} is below zero",
                    'message' => "{$b['name']} balance is " . $m($b['balance']) . '. Record an opening balance, a transfer or an adjustment.',
                    'amount' => $b['balance'], 'count' => 1, 'href' => '/dashboard/finance/transactions?accountId=' . $b['accountId']];
            }
        }

        return $out;
    }
}
