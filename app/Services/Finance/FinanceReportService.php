<?php

namespace App\Services\Finance;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Finance reports. Revenue / COGS / expenses use exactly the definitions of ReportService::pnl()
 * (non-cancelled sales by order time, line total_cost, expenses by expense date) so the numbers match
 * the existing P&L report; manual finance entries add "other income / other expenses".
 */
class FinanceReportService
{
    public const STATEMENT_CAP = 5000;

    public function __construct(
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceAccountService $accounts,
        private readonly FinanceObligationService $obligations,
        private readonly FinanceMetricsService $metrics,
        private readonly FinanceCloseService $closes,
    ) {
    }

    /** Datetime column window in the company time zone (delegates to the metrics service). */
    public function window(Builder $q, string $col, ?string $from, ?string $to, ?int $cid = null): Builder
    {
        return $this->metrics->window($q, $col, $from, $to, $cid);
    }

    private static function pct(?float $cur, ?float $prev): ?float
    {
        if ($cur === null || $prev === null || abs($prev) < 0.005) {
            return null;
        }

        return round(($cur - $prev) / abs($prev) * 100, 2);
    }

    // ─── P&L ────────────────────────────────────────────────────────────────

    /** P&L figures (FinanceMetricsService::pnl) plus the product-profit coverage block. */
    public function pnlFigures(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        return $this->metrics->pnl($cid, $from, $to, $channel) + ['productSales' => $this->metrics->productSales($cid, $from, $to, $channel)];
    }

    public function pnl(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $compare = ($in['compare'] ?? 'previous') !== 'none';
        $cur = $this->pnlFigures($cid, $period->fromDate(), $period->toDate(), $channel);
        $prev = null;
        $changes = null;
        if ($compare && $period->hasPrevious()) {
            $p = $period->previous();
            $prev = $this->pnlFigures($cid, $p->fromDate(), $p->toDate(), $channel);
            $changes = [];
            foreach (['revenue', 'cogs', 'grossProfit', 'expenses', 'paymentFees', 'otherIncome', 'otherExpenses', 'netProfit'] as $k) {
                $changes[$k] = self::pct($cur[$k], $prev[$k]);
            }
        }
        $closes = $this->closes->closedRows($cid);
        $months = array_map(function ($m) use ($closes) {
            $c = $closes[$m['key']] ?? null;

            return $m + ['closed' => (bool) ($c && $c->is_closed), 'netAtClose' => $c && $c->is_closed && $c->net_at_close !== null ? round((float) $c->net_at_close, 2) : null];
        }, $this->metrics->pnlMonths($cid, $period->fromDate(), $period->toDate(), $channel));

        return [
            'period' => $period->toArray(),
            'current' => $cur,
            'previous' => $prev,
            'changes' => $changes,
            'months' => $months,
            'closeStatus' => $this->closes->status($cid),
            'channelScope' => 'sales_only',
            'context' => FinanceContext::context($cid, $period, $channel),
        ];
    }

    // ─── Cash flow ──────────────────────────────────────────────────────────

    /** Money in/out (transfers excluded) for a window. */
    public function moneyInOut(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        return $this->metrics->moneyInOut($cid, $from, $to, $channel);
    }

    /** In/out per day or month (transfers excluded). */
    public function series(int $cid, FinancePeriod $period, string $channel = 'all'): array
    {
        $gran = $period->granularity();
        $expr = $gran === 'day' ? "DATE_FORMAT(l.txn_date, '%Y-%m-%d')" : "DATE_FORMAT(l.txn_date, '%Y-%m')";
        $data = [];
        $min = null;
        foreach ($this->ledger->applyPeriod($this->ledger->base($cid), $period->fromDate(), $period->toDate())
            ->where('l.source_type', '!=', 'transfer')->when($channel !== 'all', fn ($q) => $q->where('l.channel', $channel))->groupByRaw($expr)
            ->selectRaw("{$expr} AS k, SUM(CASE WHEN l.direction = 'in' THEN l.amount ELSE 0 END) AS i, SUM(CASE WHEN l.direction = 'out' THEN l.amount ELSE 0 END) AS o")
            ->get() as $r) {
            $data[$r->k] = [(float) $r->i, (float) $r->o];
            $min = $min === null || $r->k < $min ? $r->k : $min;
        }
        $points = [];
        foreach ($period->buckets($min ? ($gran === 'day' ? $min : $min . '-01') : null) as $b) {
            [$i, $o] = $data[$b['key']] ?? [0.0, 0.0];
            $points[] = ['key' => $b['key'], 'label' => $b['label'], 'moneyIn' => round($i, 2), 'moneyOut' => round($o, 2), 'net' => round($i - $o, 2)];
        }

        return ['granularity' => $gran, 'points' => $points];
    }

    public function cashflow(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $from = $period->fromDate();
        $to = $period->toDate();
        $map = $this->accounts->map($cid);

        $bySource = [];
        foreach ($this->ledger->applyPeriod($this->ledger->base($cid), $from, $to)->where('l.source_type', '!=', 'transfer')
            ->groupBy('l.source_type')
            ->selectRaw("l.source_type, SUM(CASE WHEN l.direction = 'in' THEN l.amount ELSE 0 END) AS i, SUM(CASE WHEN l.direction = 'out' THEN l.amount ELSE 0 END) AS o, COUNT(*) AS n")
            ->get() as $r) {
            $bySource[] = [
                'sourceType' => $r->source_type,
                'label' => FinanceLedgerService::SOURCE_LABELS[$r->source_type] ?? $r->source_type,
                'moneyIn' => round((float) $r->i, 2), 'moneyOut' => round((float) $r->o, 2),
                'net' => round((float) $r->i - (float) $r->o, 2), 'count' => (int) $r->n,
            ];
        }
        usort($bySource, fn ($a, $b) => ($b['moneyIn'] + $b['moneyOut']) <=> ($a['moneyIn'] + $a['moneyOut']));

        $openings = $from ? $this->accounts->balances($cid, $map, Carbon::parse($from)->subDay()->toDateString()) : null;
        $act = $this->accounts->activity($cid, $map, $from, $to);
        $byAccount = [];
        $open = 0.0;
        foreach ($map->accounts as $id => $a) {
            $o = $openings !== null ? $openings[$id] : round((float) $a->opening_balance, 2);
            $x = $act[$id];
            $mi = round($x['in'] - $x['transfersIn'], 2);
            $mo = round($x['out'] - $x['transfersOut'], 2);
            $byAccount[] = [
                'accountId' => $id, 'name' => $a->name, 'type' => $a->type, 'isActive' => (bool) $a->is_active,
                'openingBalance' => $o, 'moneyIn' => $mi, 'moneyOut' => $mo,
                'transfersIn' => $x['transfersIn'], 'transfersOut' => $x['transfersOut'],
                'closingBalance' => round($o + $x['in'] - $x['out'], 2),
            ];
            $open += $o;
        }
        $t = $this->moneyInOut($cid, $from, $to);
        $series = $this->series($cid, $period);

        return [
            'period' => $period->toArray(),
            'openingBalance' => round($open, 2),
            'moneyIn' => $t['moneyIn'],
            'moneyOut' => $t['moneyOut'],
            'net' => $t['net'],
            'closingBalance' => round($open + $t['net'], 2),
            'bySource' => $bySource,
            'byAccount' => $byAccount,
            'series' => $series['points'],
            'granularity' => $series['granularity'],
            'context' => FinanceContext::context($cid, $period),
        ];
    }

    // ─── Account statement ──────────────────────────────────────────────────

    public function statement(int $cid, array $in): array
    {
        if (empty($in['accountId'])) {
            throw new HttpException(422, 'Choose an account.');
        }
        $period = FinanceContext::period($cid, $in, 'this_month');
        $account = $this->accounts->find((int) $in['accountId'], $cid);
        $map = $this->accounts->map($cid);
        [$opening, $closing, $running] = $this->ledger->runningBalances($cid, $map, $account->id, $period->fromDate(), $period->toDate());

        $q = $this->ledger->applyPeriod($this->ledger->base($cid), $period->fromDate(), $period->toDate());
        $map->applyAccountFilter($q, $account->id);
        $tot = $this->ledger->totals($q, $cid);
        $rows = $this->ledger->ordered($q, 'date_asc')->limit(self::STATEMENT_CAP)->get()
            ->map(fn ($r) => $this->ledger->mapRow($r, $map, $running[$r->uid] ?? null))->all();

        return [
            'account' => $this->accounts->get($account->id, $cid)->toArray(),
            'period' => $period->toArray(),
            'openingBalance' => $opening,
            'moneyIn' => $tot['moneyIn'],
            'moneyOut' => $tot['moneyOut'],
            'closingBalance' => $closing,
            'rows' => $rows,
            'truncated' => $tot['count'] > self::STATEMENT_CAP,
            'fees' => $tot['fees'],
            'context' => FinanceContext::context($cid, $period),
        ];
    }

    // ─── Tax ────────────────────────────────────────────────────────────────

    public function tax(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'this_month');
        $channel = FinanceContext::channel($in);
        $from = $period->fromDate();
        $to = $period->toDate();
        $sells = fn () => $this->metrics->sells($cid, $from, $to, $channel);
        $t = $sells()->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount),0) AS total,
            COALESCE(SUM(CASE WHEN tax_amount > 0 THEN 1 ELSE 0 END),0) AS taxed, COALESCE(SUM(tax_amount),0) AS tax,
            COALESCE(SUM(CASE WHEN tax_amount > 0 THEN amount - tax_amount ELSE 0 END),0) AS taxable')->first();

        $name = $this->taxName($cid);
        $byRate = [];
        foreach ($sells()->where('tax_amount', '>', 0)->where('tax_rate', '>', 0)->groupBy('tax_rate')
            ->selectRaw('tax_rate, COUNT(*) AS n, SUM(amount - tax_amount) AS taxable, SUM(tax_amount) AS tax')->orderBy('tax_rate')->get() as $r) {
            $rate = (float) $r->tax_rate;
            $label = $name . ' ' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%';
            $byRate[] = ['rate' => $rate, 'name' => $label, 'label' => $label, 'orders' => (int) $r->n, 'taxableAmount' => round((float) $r->taxable, 2), 'tax' => round((float) $r->tax, 2)];
        }
        $nb = $sells()->where('tax_amount', '>', 0)->where(fn ($w) => $w->whereNull('tax_rate')->orWhere('tax_rate', '<=', 0))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount - tax_amount),0) AS taxable, COALESCE(SUM(tax_amount),0) AS tax')->first();
        $notBroken = (float) $nb->tax > 0 ? [
            'label' => 'Not broken down (orders before line tax was recorded)',
            'orders' => (int) $nb->n, 'taxableAmount' => round((float) $nb->taxable, 2), 'tax' => round((float) $nb->tax, 2),
        ] : null;

        $mk = "DATE_FORMAT(" . FinanceContext::localSql($cid, 'sells.order_time') . ", '%Y-%m')";
        $byMonth = [];
        foreach ($sells()->where('tax_amount', '>', 0)->groupByRaw($mk)
            ->selectRaw("{$mk} AS k, COUNT(*) AS n, SUM(amount - tax_amount) AS taxable, SUM(tax_amount) AS tax")->orderBy('k')->get() as $r) {
            $byMonth[] = ['key' => $r->k, 'label' => Carbon::createFromFormat('!Y-m', $r->k)->format('M Y'), 'orders' => (int) $r->n, 'taxableAmount' => round((float) $r->taxable, 2), 'tax' => round((float) $r->tax, 2)];
        }
        $taxable = (float) $t->taxable;
        $collected = round((float) $t->tax, 2);
        $sum = round(array_sum(array_column($byRate, 'tax')) + ($notBroken['tax'] ?? 0), 2);

        return [
            'period' => $period->toArray(),
            'taxCollected' => $collected,
            'taxableSales' => round($taxable, 2),
            'salesTotal' => round((float) $t->total, 2),
            'orders' => (int) $t->n,
            'taxedOrders' => (int) $t->taxed,
            'effectiveRatePct' => $taxable > 0 ? round((float) $t->tax / $taxable * 100, 2) : null,
            'byRate' => $byRate,
            'notBrokenDown' => $notBroken,
            'reconciles' => abs($sum - $collected) < 0.01,
            'byMonth' => $byMonth,
            'currency' => FinanceContext::currency($cid),
            'context' => FinanceContext::context($cid, $period, $channel),
        ];
    }

    /** Tax name from company settings (VAT / GST / Sales tax), else "Tax". */
    public function taxName(int $cid): string
    {
        $t = strtolower(trim((string) \App\Models\CompanySettings::where('company_id', $cid)->value('tax_id_type')));

        return match (true) {
            str_contains($t, 'vat') || str_contains($t, 'bin') => 'VAT',
            str_contains($t, 'gst') => 'GST',
            str_contains($t, 'sales') => 'Sales tax',
            default => 'Tax',
        };
    }

    // ─── Aging ──────────────────────────────────────────────────────────────

    public function aging(int $cid): array
    {
        $cust = $this->obligations->dueSummary('customer', $cid, ['per_page' => 500, 'sort' => 'amount']);
        $vend = $this->obligations->dueSummary('vendor', $cid, ['per_page' => 500, 'sort' => 'amount']);
        $bucket = fn (int $d) => $d <= 30 ? 0 : ($d <= 60 ? 1 : ($d <= 90 ? 2 : 3));
        $rec = $this->obligations->receivablesSummary($cid);
        $pay = $this->obligations->payablesSummary($cid, 0);

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'asOf' => FinanceContext::todayDate($cid),
            'receivables' => [
                'total' => $rec['total'],
                'buckets' => $cust['data']['aging'],
                'customers' => array_map(fn ($r) => ['partyId' => $r['partyId'], 'name' => $r['name'], 'due' => $r['due'], 'ageDays' => $r['ageDays'], 'bucket' => $bucket((int) $r['ageDays'])], array_slice($cust['data']['rows'], 0, 100)),
                'customerDues' => $rec['customerDues']['total'],
                'codWithCouriers' => $rec['codWithCouriers']['total'],
                'tailorBalances' => $rec['tailorBalances']['total'],
            ],
            'payables' => [
                'total' => $pay['total'],
                'buckets' => $vend['data']['aging'],
                'suppliers' => array_map(fn ($r) => ['partyId' => $r['partyId'], 'name' => $r['name'], 'due' => $r['due'], 'ageDays' => $r['ageDays'], 'bucket' => $bucket((int) $r['ageDays']), 'overdue' => (bool) ($r['overdue'] ?? false)], array_slice($vend['data']['rows'], 0, 100)),
                'supplierDues' => $pay['supplierDues']['total'],
                'unpaidSalaries' => $pay['unpaidSalaries']['total'],
                'dorjiOwed' => $pay['dorjiOwed']['total'],
            ],
        ];
    }
}
