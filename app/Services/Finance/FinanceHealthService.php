<?php

namespace App\Services\Finance;

use App\Support\Money;

/**
 * Reconciliation checks R2–R11 (Storify audit §10) on live data, for the Reports "Health checks" panel,
 * GET /finance/health and `php artisan finance:reconcile`. Each check compares figures produced by the
 * page services the UI uses, so a failing check means two pages disagree.
 */
class FinanceHealthService
{
    public function __construct(
        private readonly FinanceMetricsService $metrics,
        private readonly FinanceOverviewService $overview,
        private readonly FinancePartyService $parties,
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceReportService $reports,
        private readonly FinanceStockService $stock,
        private readonly FinanceAccountService $accounts,
    ) {
    }

    private static function close(float $a, float $b): bool
    {
        return abs(round($a - $b, 2)) < 0.01;
    }

    private function check(string $id, string $title, bool $ok, ?float $expected, ?float $actual, array $numbers, string $explanation, ?string $status = null): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'status' => $status ?? ($ok ? 'pass' : 'fail'),
            'expected' => $expected,
            'actual' => $actual,
            'difference' => $expected !== null && $actual !== null ? round($actual - $expected, 2) : null,
            'numbers' => $numbers,
            'explanation' => $explanation,
        ];
    }

    public function run(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $from = $period->fromDate();
        $to = $period->toDate();
        $m = fn (float $v) => Money::format($cid, $v);
        $q = ['preset' => $period->preset, 'from' => $from, 'to' => $to, 'channel' => $channel];
        if ($period->preset !== 'custom') {
            unset($q['from'], $q['to']);
        }
        $ov = $this->overview->overview($cid, $q);
        $pay = $this->parties->paymentsSummary($cid, $q);
        $checks = [];

        // R2 costed + uncosted + excluded = product sales
        $ps = $ov['metrics']['productSales'];
        $sum = round($ps['costed'] + $ps['uncosted'] + $ps['excluded'], 2);
        $line = (float) $this->metrics->lines($cid, $from, $to, $channel)->sum('order_items.total_price');
        $ok = self::close($sum, $ps['total']) && self::close($ps['total'], $line);
        $checks[] = $this->check('R2', 'Costed + uncosted (+ excluded) sales = product sales', $ok, round($line, 2), $sum,
            ['productSales' => $ps['total'], 'costed' => $ps['costed'], 'uncosted' => $ps['uncosted'], 'excluded' => $ps['excluded'], 'lineTotals' => round($line, 2)],
            $ok ? "Costed {$m($ps['costed'])} + uncosted {$m($ps['uncosted'])} + excluded {$m($ps['excluded'])} = product sales {$m($ps['total'])}."
                : "The parts add up to {$m($sum)} but product sales (Σ line totals) are {$m($line)}.");

        // R3 paid ≤ order volume
        $vol = $pay['orderVolume']['amount'];
        $paid = $pay['paidVolume']['total'];
        $ok = $paid <= $vol + 0.004 && self::close($vol, $ov['metrics']['orders']['volume']);
        $checks[] = $this->check('R3', 'Paid volume is not more than order volume', $ok, $vol, $paid,
            ['orderVolume' => $vol, 'overviewOrderVolume' => $ov['metrics']['orders']['volume'], 'paidRecorded' => $pay['paidVolume']['recorded'], 'paidEstimated' => $pay['paidVolume']['estimated']],
            $ok ? "Paid volume {$m($paid)} ≤ order volume {$m($vol)}." : "Paid volume {$m($paid)} is more than order volume {$m($vol)}, or Overview and Payments disagree on order volume.");

        // R4 refunds equal on Overview, Payments and Σ ledger refund rows
        $map = $this->accounts->map($cid);
        $lq = $this->ledger->applyFilters($this->ledger->applyPeriod($this->ledger->base($cid), $from, $to), $map, ['sourceTypes' => ['refund'], 'channel' => $channel]);
        $rows = $this->ledger->ordered($lq)->get();
        $ledgerRefunds = round(-array_sum(array_map(fn ($r) => $this->ledger->mapRow($r, $map)['gross'], $rows->all())), 2);
        $a = $ov['metrics']['refunds']['refunded'];
        $b = $pay['refunds']['refunded'];
        $ok = self::close($a, $b) && self::close($a, $ledgerRefunds);
        $checks[] = $this->check('R4', 'Refunds agree on Overview, Payments and Transactions', $ok, $a, $ledgerRefunds,
            ['overview' => $a, 'payments' => $b, 'transactions' => $ledgerRefunds, 'transactionRows' => $rows->count(), 'awaiting' => $pay['refunds']['awaiting']],
            $ok ? "Refunded {$m($a)} on all three." : "Overview {$m($a)}, Payments {$m($b)}, Σ refund rows {$m($ledgerRefunds)}.");

        // R5 Σ row fees = overview fees; net = gross − fee on every row
        $all = $this->ledger->applyFilters($this->ledger->applyPeriod($this->ledger->base($cid), $from, $to), $map, ['channel' => $channel]);
        $feeSum = 0.0;
        $bad = 0;
        $n = 0;
        foreach ($all->where('l.source_type', '!=', 'transfer')->cursor() as $r) {
            $row = $this->ledger->mapRow($r, $map);
            $feeSum += $row['fee'];
            $n++;
            if (!self::close($row['net'], $row['gross'] - $row['fee']) || ($row['direction'] === 'out' && ($row['gross'] > 0 || $row['net'] > 0))) {
                $bad++;
            }
        }
        $feeSum = round($feeSum, 2);
        $ovFees = $ov['kpis']['paymentFees']['value'];
        $ok = self::close($feeSum, $ovFees) && $bad === 0;
        $checks[] = $this->check('R5', 'Σ transaction fees = Overview payment fees; net = gross − fee', $ok, $ovFees, $feeSum,
            ['overviewFees' => $ovFees, 'transactionFees' => $feeSum, 'rows' => $n, 'rowsWithBadNet' => $bad],
            $ok ? "Fees {$m($feeSum)} on {$n} rows; every row has net = gross − fee and money out is negative."
                : "Σ row fees {$m($feeSum)} vs Overview {$m($ovFees)}; {$bad} rows break net = gross − fee.");

        // R6 Σ supplier owed (payables per party) = overview supplier dues
        $sup = array_filter($this->parties->payable($cid, ['type' => 'supplier'])['rows'], fn ($r) => $r['type'] === 'supplier');
        $owed = round(array_sum(array_column($sup, 'owed')), 2);
        $ovSup = $ov['payables']['supplierDues'];
        $ok = self::close($owed, $ovSup);
        $checks[] = $this->check('R6', 'Supplier balances (Payouts) = Overview owed to suppliers', $ok, $ovSup, $owed,
            ['overviewSupplierDues' => $ovSup, 'payablesOwed' => $owed, 'suppliers' => count($sup)],
            $ok ? "Owed to suppliers {$m($owed)} on both pages." : "Payouts lists {$m($owed)} owed but Overview shows {$m($ovSup)}.");

        // R7 customers tab total = overview owed by customers
        $cust = $this->parties->customers($cid, ['perPage' => 1])['data']['totals']['due'];
        $ovCust = round($ov['receivables']['customerDues'] + $ov['receivables']['codWithCouriers'], 2);
        $ok = self::close($cust, $ovCust);
        $checks[] = $this->check('R7', 'Receivables customers total = Overview owed by customers', $ok, $ovCust, $cust,
            ['overviewOwedByCustomers' => $ovCust, 'customersTab' => $cust],
            $ok ? "Owed by customers {$m($cust)} on both pages." : "Customers tab {$m($cust)} vs Overview {$m($ovCust)}.");

        // R8 stock value − movement value = 0, or fully explained
        $rec = $this->stock->reconciliation($cid, 0);
        $explainedOk = self::close($rec['explained'], $rec['difference']);
        $status = abs($rec['difference']) < 0.01 ? 'pass' : ($explainedOk ? 'info' : 'fail');
        $kinds = implode(', ', array_map(fn ($k) => $k['label'] . ' ' . $m($k['value']), $rec['byKind']));
        $checks[] = $this->check('R8', 'Stock value − stock movements = 0 (or explained)', $status !== 'fail', $rec['stockValue'], $rec['movementValue'],
            ['stockValue' => $rec['stockValue'], 'movementValue' => $rec['movementValue'], 'difference' => $rec['difference'], 'explained' => $rec['explained'], 'unexplained' => $rec['unexplained']],
            $status === 'pass' ? 'Stock value matches the stock movements.'
                : ($explainedOk ? "Gap {$m($rec['difference'])} fully explained: {$kinds}." : "Gap {$m($rec['difference'])}, only {$m($rec['explained'])} explained."),
            $status);

        // R9 Σ tax by rate + not broken down = collected
        $tax = $this->reports->tax($cid, $q);
        $tsum = round(array_sum(array_column($tax['byRate'], 'tax')) + ($tax['notBrokenDown']['tax'] ?? 0), 2);
        $ok = self::close($tsum, $tax['taxCollected']) && self::close($tax['taxCollected'], $ov['metrics']['tax']['collected']);
        $checks[] = $this->check('R9', 'Tax by rate (+ not broken down) = tax collected', $ok, $tax['taxCollected'], $tsum,
            ['collected' => $tax['taxCollected'], 'byRate' => round(array_sum(array_column($tax['byRate'], 'tax')), 2), 'notBrokenDown' => $tax['notBrokenDown']['tax'] ?? 0.0],
            $ok ? "Tax lines add up to collected {$m($tsum)}." : "Tax lines {$m($tsum)} vs collected {$m($tax['taxCollected'])}.");

        // R10 Σ month nets (closed + open) = net for the whole period
        $months = $this->metrics->pnlMonths($cid, $from, $to, $channel);
        $closedRows = FinancePeriodLock::closedMonths($cid);
        $closedNet = round(array_sum(array_map(fn ($x) => isset($closedRows[$x['key']]) ? $x['netProfit'] : 0, $months)), 2);
        $openNet = round(array_sum(array_map(fn ($x) => isset($closedRows[$x['key']]) ? 0 : $x['netProfit'], $months)), 2);
        $net = $ov['kpis']['netProfit']['value'];
        $ok = self::close($closedNet + $openNet, $net);
        $checks[] = $this->check('R10', 'Closed months + open months = net for the period', $ok, $net, round($closedNet + $openNet, 2),
            ['closedMonthsNet' => $closedNet, 'openMonthsNet' => $openNet, 'periodNet' => $net, 'months' => count($months)],
            $ok ? "Closed {$m($closedNet)} + open {$m($openNet)} = net {$m($net)}." : "Months add up to {$m($closedNet + $openNet)} but the period net is {$m($net)}.");

        // R11 single currency
        $cur = FinanceContext::currency($cid);
        $checks[] = $this->check('R11', 'No figure adds up different currencies', true, null, null, ['currency' => $cur['code']],
            "The company works in one currency ({$cur['code']}); every amount is in {$cur['code']}.");

        $failed = count(array_filter($checks, fn ($c) => $c['status'] === 'fail'));

        return [
            'context' => FinanceContext::context($cid, $period, $channel),
            'passed' => count(array_filter($checks, fn ($c) => $c['status'] === 'pass')),
            'failed' => $failed,
            'info' => count(array_filter($checks, fn ($c) => $c['status'] === 'info')),
            'ok' => $failed === 0,
            'checks' => $checks,
        ];
    }
}
