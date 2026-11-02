<?php

namespace App\Services\Finance;

use App\Models\Sell;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place finance figures are defined (FND-1). Overview, Payments, Reports, Receivables and the
 * health checks all read from here, so the same period + channel always give the same numbers.
 *
 * Definitions
 *  - Order volume: Σ amount of sales PLACED in the period (order time in the company time zone), not cancelled/deleted.
 *  - Paid volume: money received on those same sales (Σ sale payments "in", any date) capped per sale at its amount
 *    (= recorded). Sales marked paid without any payment row are "estimated", kept apart. recorded + estimated ≤ volume.
 *  - Product sales: Σ line totals of those sales = costed (unit cost > 0) + uncosted (product without cost)
 *    + excluded (custom lines without a product).
 *  - Refunds: by refund date = ledger "refund" lines in the period; awaiting = pending customer returns with a refund amount.
 *  - Fees: estimated payment-method fees on customer money-in lines paid in the period (FinanceFeeService).
 *  - Expenses: expense rows by expense date minus voids by void date (a void never changes its original month).
 *  - Net profit: revenue − COGS − expenses − payment fees + other income − other expenses.
 */
class FinanceMetricsService
{
    public function __construct(
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceFeeService $fees,
    ) {
    }

    // ─── Windows ────────────────────────────────────────────────────────────

    /** UTC timestamp column window for local days [from 00:00, to + 1 day 00:00) in the company zone. */
    public function window(Builder $q, string $col, ?string $from, ?string $to, ?int $cid = null): Builder
    {
        if ($from) {
            $q->where($col, '>=', $cid ? FinanceContext::utcStartOf($cid, $from) : Carbon::parse($from)->startOfDay()->toDateTimeString());
        }
        if ($to) {
            $next = Carbon::parse($to)->addDay()->toDateString();
            $q->where($col, '<', $cid ? FinanceContext::utcStartOf($cid, $next) : Carbon::parse($next)->startOfDay()->toDateTimeString());
        }

        return $q;
    }

    public function sells(int $cid, ?string $from, ?string $to, string $channel = 'all'): Builder
    {
        $q = DB::table('sells')->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled');
        FinanceContext::applyChannel($q, $channel, 'sells');

        return $this->window($q, 'sells.order_time', $from, $to, $cid);
    }

    public function lines(int $cid, ?string $from, ?string $to, string $channel = 'all'): Builder
    {
        $q = DB::table('order_items')->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled');
        FinanceContext::applyChannel($q, $channel, 'sells');

        return $this->window($q, 'sells.order_time', $from, $to, $cid);
    }

    private function dateWindow(Builder $q, string $col, ?string $from, ?string $to): Builder
    {
        return $q->when($from, fn ($x) => $x->where($col, '>=', $from))->when($to, fn ($x) => $x->where($col, '<=', $to));
    }

    /** Expense rows by expense date (voided ones included: they stay in their own month). */
    public function expenseRows(int $cid, ?string $from, ?string $to): Builder
    {
        return $this->dateWindow(DB::table('expenses')->where('expenses.company_id', $cid)->whereNull('expenses.deleted_at'), 'expenses.expense_date', $from, $to);
    }

    /** Voided expenses by void date. */
    public function voidRows(int $cid, ?string $from, ?string $to): Builder
    {
        return $this->dateWindow(DB::table('expenses')->where('expenses.company_id', $cid)->whereNull('expenses.deleted_at')->whereNotNull('expenses.voided_at'), 'expenses.voided_at', $from, $to);
    }

    private function ledgerIn(int $cid, ?string $from, ?string $to, string $channel): Builder
    {
        $q = $this->ledger->applyPeriod($this->ledger->base($cid), $from, $to);
        if ($channel !== 'all') {
            $q->where('l.channel', $channel);
        }

        return $q;
    }

    // ─── Building blocks ────────────────────────────────────────────────────

    public function orders(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $r = $this->sells($cid, $from, $to, $channel)->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount),0) AS v, COALESCE(SUM(tax_amount),0) AS t')->first();

        return ['count' => (int) $r->n, 'volume' => round((float) $r->v, 2), 'tax' => round((float) $r->t, 2)];
    }

    /** Paid volume on the sales placed in the period (recorded vs estimated), never above order volume. */
    public function paid(int $cid, ?string $from, ?string $to, string $channel = 'all', ?float $volume = null): array
    {
        $pay = DB::table('due_payments')->where('company_id', $cid)->where('payable_type', Sell::class)
            ->groupBy('payable_id')
            ->selectRaw("payable_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END) AS rec, COUNT(*) AS n");
        $r = $this->sells($cid, $from, $to, $channel)
            ->leftJoinSub($pay, 'p', 'p.payable_id', '=', 'sells.id')
            ->selectRaw("COALESCE(SUM(CASE WHEN p.n IS NOT NULL THEN GREATEST(0, LEAST(sells.amount, p.rec)) ELSE 0 END),0) AS rec,
                COALESCE(SUM(CASE WHEN p.n IS NULL AND (sells.paid_amount > 0 OR sells.payment_status = 'paid')
                    THEN GREATEST(0, LEAST(sells.amount, CASE WHEN sells.paid_amount > 0 THEN sells.paid_amount ELSE sells.amount END)) ELSE 0 END),0) AS est,
                COALESCE(SUM(CASE WHEN p.n IS NULL AND (sells.paid_amount > 0 OR sells.payment_status = 'paid') THEN 1 ELSE 0 END),0) AS est_n")
            ->first();
        $rec = round((float) $r->rec, 2);
        $est = round((float) $r->est, 2);
        $volume ??= $this->orders($cid, $from, $to, $channel)['volume'];

        return [
            'recorded' => $rec,
            'estimated' => $est,
            'total' => round($rec + $est, 2),
            'unpaid' => round(max(0, $volume - $rec - $est), 2),
            'estimatedCount' => (int) $r->est_n,
        ];
    }

    /** costed + uncosted + excluded = total (same line set). */
    public function productSales(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $r = $this->lines($cid, $from, $to, $channel)->selectRaw("COALESCE(SUM(order_items.total_price),0) AS t,
            COALESCE(SUM(CASE WHEN order_items.product_id IS NULL THEN order_items.total_price ELSE 0 END),0) AS ex,
            COALESCE(SUM(CASE WHEN order_items.product_id IS NOT NULL AND COALESCE(order_items.unit_cost,0) > 0 THEN order_items.total_price ELSE 0 END),0) AS c,
            COALESCE(SUM(CASE WHEN order_items.product_id IS NOT NULL AND COALESCE(order_items.unit_cost,0) <= 0 THEN 1 ELSE 0 END),0) AS un")->first();
        $total = round((float) $r->t, 2);
        $costed = round((float) $r->c, 2);
        $excluded = round((float) $r->ex, 2);
        $uncosted = round($total - $costed - $excluded, 2);

        return [
            'total' => $total,
            'costed' => $costed,
            'uncosted' => $uncosted,
            'excluded' => $excluded,
            'costedPct' => $total != 0.0 ? round($costed / $total * 100, 2) : null,
            'uncostedLines' => (int) $r->un,
            'productsWithoutCost' => $this->productsWithoutCostCount($cid),
        ];
    }

    /** Products (not bundles) without any cost that have stock or sales. */
    public function productsWithoutCostQuery(int $cid): Builder
    {
        return DB::table('products as p')->where('p.company_id', $cid)->whereNull('p.deleted_at')->where('p.is_bundle', 0)
            ->where(fn ($w) => $w->whereNull('p.cost_price')->orWhere('p.cost_price', '<=', 0))
            ->whereNotExists(fn ($x) => $x->selectRaw('1')->from('product_variants as v')->whereColumn('v.product_id', 'p.id')->whereNull('v.deleted_at')->where('v.cost_price', '>', 0))
            ->where(fn ($w) => $w->where('p.stock', '>', 0)->orWhereExists(fn ($x) => $x->selectRaw('1')->from('order_items as oi')->whereColumn('oi.product_id', 'p.id')));
    }

    /** GET /finance/products/without-cost */
    public function productsWithoutCost(int $cid, array $in): array
    {
        $period = FinanceContext::period($cid, $in, 'all_time');
        $channel = FinanceContext::channel($in);
        $q = $this->productsWithoutCostQuery($cid);
        $s = trim((string) ($in['search'] ?? ''));
        if ($s !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
            $q->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.sku', 'like', $like));
        }
        $sales = $this->lines($cid, $period->fromDate(), $period->toDate(), $channel)->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id AS pid, SUM(order_items.quantity) AS u, SUM(CASE WHEN COALESCE(order_items.unit_cost,0) <= 0 THEN order_items.total_price ELSE 0 END) AS v');
        $q->leftJoinSub($sales, 'x', 'x.pid', '=', 'p.id');
        $total = (clone $q)->count();
        $sum = (float) (clone $q)->sum('x.v');
        $perPage = max(1, min(100, (int) ($in['perPage'] ?? $in['per_page'] ?? 25)));
        $page = max(1, (int) ($in['page'] ?? 1));
        $rows = $q->orderByRaw('COALESCE(x.v, 0) DESC')->orderBy('p.name')->forPage($page, $perPage)
            ->get(['p.id', 'p.name', 'p.sku', 'p.stock', 'p.price', DB::raw('COALESCE(x.u, 0) AS u'), DB::raw('COALESCE(x.v, 0) AS v')])
            ->map(fn ($r) => [
                'productId' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'stock' => (int) $r->stock,
                'unitsSold' => (int) $r->u, 'uncostedSales' => round((float) $r->v, 2), 'price' => round((float) $r->price, 2),
                'editHref' => '/dashboard/products/' . $r->id,
            ])->all();

        return [
            'data' => ['context' => FinanceContext::context($cid, $period, $channel), 'rows' => $rows, 'totals' => ['products' => $total, 'uncostedSales' => round($sum, 2)]],
            'meta' => ['total' => $total, 'perPage' => $perPage, 'currentPage' => $page, 'lastPage' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    public function productsWithoutCostCount(int $cid): int
    {
        return (int) $this->productsWithoutCostQuery($cid)->count();
    }

    public function refunds(int $cid, ?string $from, ?string $to, ?FinancePeriod $period = null, string $channel = 'all'): array
    {
        $r = $this->ledgerIn($cid, $from, $to, $channel)->where('l.source_type', 'refund')
            ->selectRaw('COALESCE(SUM(l.amount),0) AS a, COUNT(*) AS n')->first();
        $aw = $this->awaitingRefunds($cid, $from, $to, $channel);
        $label = 'Refunds in ' . ($period ? lcfirst($period->toArray()['label']) : 'this period');
        if ($period && $period->preset === 'custom') {
            $label = 'Refunds in ' . $period->toArray()['label'];
        }

        return [
            'refunded' => round((float) $r->a, 2),
            'refundedCount' => (int) $r->n,
            'awaiting' => $aw['amount'],
            'awaitingCount' => $aw['count'],
            'label' => $label,
        ];
    }

    /** Pending customer returns with a refund amount (requested in the window; null window = all, i.e. current state). */
    public function awaitingRefunds(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $q = DB::table('customer_returns as cr')->leftJoin('sells', 'sells.id', '=', 'cr.order_id')
            ->where('cr.company_id', $cid)->whereNull('cr.deleted_at')->where('cr.status', 'pending')->where('cr.refund_amount', '>', 0);
        if ($channel !== 'all') {
            FinanceContext::applyChannel($q, $channel, 'sells');
        }
        if ($from) {
            $q->whereRaw('COALESCE(cr.request_date, cr.created_at) >= ?', [FinanceContext::utcStartOf($cid, $from)]);
        }
        if ($to) {
            $q->whereRaw('COALESCE(cr.request_date, cr.created_at) < ?', [FinanceContext::utcStartOf($cid, Carbon::parse($to)->addDay()->toDateString())]);
        }
        $r = $q->selectRaw('COALESCE(SUM(cr.refund_amount),0) AS a, COUNT(*) AS n')->first();

        return ['amount' => round((float) $r->a, 2), 'count' => (int) $r->n];
    }

    public function fees(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $fee = $this->fees->sql($cid);
        $src = FinanceFeeService::FEE_SOURCES;
        $rows = $this->ledgerIn($cid, $from, $to, $channel)->where('l.direction', 'in')->whereIn('l.source_type', $src)
            ->groupBy('l.mkey')->selectRaw("l.mkey, COALESCE(SUM(l.amount),0) AS g, COALESCE(SUM({$fee}),0) AS f, COUNT(*) AS n")->get();
        $by = [];
        $total = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $f = round((float) $r->f, 2);
            $total += $f;
            if ($f > 0) {
                $count += (int) $r->n;
                $by[] = ['methodKey' => $r->mkey, 'label' => FinanceMethods::label($r->mkey), 'gross' => round((float) $r->g, 2), 'fee' => $f, 'count' => (int) $r->n];
            }
        }
        usort($by, fn ($a, $b) => $b['fee'] <=> $a['fee']);

        return ['total' => round($total, 2), 'count' => $count, 'byMethod' => $by];
    }

    public function moneyInOut(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        return $this->ledger->totals($this->ledgerIn($cid, $from, $to, $channel)->where('l.source_type', '!=', 'transfer'), $cid);
    }

    public function storeCredit(int $cid): array
    {
        $r = DB::table('customers')->where('company_id', $cid)->whereNull('deleted_at')->where('store_credit', '>', 0)
            ->selectRaw('COALESCE(SUM(store_credit),0) AS s, COUNT(*) AS n')->first();

        return ['total' => round((float) $r->s, 2), 'customers' => (int) $r->n];
    }

    // ─── Composite blocks ───────────────────────────────────────────────────

    /** The shared metrics block (/metrics, overview.metrics). */
    public function metrics(int $cid, FinancePeriod $period, string $channel = 'all'): array
    {
        $from = $period->fromDate();
        $to = $period->toDate();
        $orders = $this->orders($cid, $from, $to, $channel);
        $cash = $this->moneyInOut($cid, $from, $to, $channel);

        return [
            'orders' => ['count' => $orders['count'], 'volume' => $orders['volume']],
            'paid' => $this->paid($cid, $from, $to, $channel, $orders['volume']),
            'productSales' => $this->productSales($cid, $from, $to, $channel),
            'refunds' => $this->refunds($cid, $from, $to, $period, $channel),
            'fees' => $this->fees($cid, $from, $to, $channel),
            'moneyIn' => $cash['moneyIn'],
            'moneyOut' => $cash['moneyOut'],
            'tax' => ['collected' => $orders['tax']],
            'storeCredit' => $this->storeCredit($cid),
        ];
    }

    /** P&L figures for a window (Finance P&L, overview KPIs, month close snapshots). */
    public function pnl(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $s = $this->sells($cid, $from, $to, $channel)->selectRaw('COALESCE(SUM(amount),0) AS rev, COUNT(*) AS n, COALESCE(SUM(tax_amount),0) AS tax,
            COALESCE(SUM(shipping_cost),0) AS ship, COALESCE(SUM(discount),0) AS disc')->first();
        $cogs = (float) $this->lines($cid, $from, $to, $channel)->sum('order_items.total_cost');
        $exGross = (float) $this->expenseRows($cid, $from, $to)->sum('amount');
        $voided = (float) $this->voidRows($cid, $from, $to)->sum('amount');
        $ex = $exGross - $voided;

        $cats = [];
        $catRows = DB::query()->fromSub(
            $this->expenseRows($cid, $from, $to)->selectRaw('expenses.expense_category_id AS cid, expenses.amount AS v')
                ->unionAll($this->voidRows($cid, $from, $to)->selectRaw('expenses.expense_category_id AS cid, -expenses.amount AS v')),
            'x'
        )->leftJoin('expense_categories as c', 'c.id', '=', 'x.cid')
            ->groupBy('x.cid')->selectRaw("x.cid AS id, COALESCE(MAX(c.name), 'Uncategorised') AS name, MAX(c.color) AS color, SUM(x.v) AS v")
            ->orderByDesc('v')->get();
        foreach ($catRows as $r) {
            $cats[] = ['categoryId' => $r->id !== null ? (int) $r->id : null, 'name' => $r->name, 'color' => $r->color, 'amount' => round((float) $r->v, 2)];
        }

        $entries = DB::table('finance_entries')->where('company_id', $cid)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'income' THEN amount ELSE 0 END),0) AS oi, COALESCE(SUM(CASE WHEN kind = 'expense' THEN amount ELSE 0 END),0) AS oe")->first();

        $led = $this->ledgerIn($cid, $from, $to, $channel)
            ->selectRaw("COALESCE(SUM(CASE WHEN l.source_type = 'refund' THEN l.amount ELSE 0 END),0) AS refunds,
                COALESCE(SUM(CASE WHEN l.uid LIKE 'salary_payment:%' OR l.uid LIKE 'dorji_payout:%' THEN l.amount ELSE 0 END),0) AS unbooked")->first();
        $fees = $this->fees($cid, $from, $to, $channel)['total'];

        $rev = (float) $s->rev;
        $gp = $rev - $cogs;
        $oi = (float) $entries->oi;
        $oe = (float) $entries->oe;
        $net = $gp - $ex - $fees + $oi - $oe;

        return [
            'revenue' => round($rev, 2),
            'cogs' => round($cogs, 2),
            'grossProfit' => round($gp, 2),
            'grossMarginPct' => $rev ? round($gp / $rev * 100, 2) : null,
            'expenses' => round($ex, 2),
            'expensesByCategory' => $cats,
            'voidedExpenses' => round($voided, 2),
            'paymentFees' => $fees,
            'otherIncome' => round($oi, 2),
            'otherExpenses' => round($oe, 2),
            'netProfit' => round($net, 2),
            'netMarginPct' => $rev ? round($net / $rev * 100, 2) : null,
            'info' => [
                'orders' => (int) $s->n,
                'tax' => round((float) $s->tax, 2),
                'shipping' => round((float) $s->ship, 2),
                'discounts' => round((float) $s->disc, 2),
                'refunds' => round((float) $led->refunds, 2),
                'payoutsWithoutExpense' => round((float) $led->unbooked, 2),
            ],
        ];
    }

    /** P&L per local calendar month. */
    public function pnlMonths(int $cid, ?string $from, ?string $to, string $channel = 'all'): array
    {
        $m = [];
        $add = function ($k, $field, $v) use (&$m) {
            if ($k === null) {
                return;
            }
            $m[$k] ??= ['revenue' => 0.0, 'cogs' => 0.0, 'expenses' => 0.0, 'paymentFees' => 0.0, 'otherIncome' => 0.0, 'otherExpenses' => 0.0];
            $m[$k][$field] += (float) $v;
        };
        $mk = "DATE_FORMAT(" . FinanceContext::localSql($cid, 'sells.order_time') . ", '%Y-%m')";
        foreach ($this->sells($cid, $from, $to, $channel)->groupByRaw($mk)->selectRaw("{$mk} AS k, SUM(amount) AS v")->get() as $r) {
            $add($r->k, 'revenue', $r->v);
        }
        foreach ($this->lines($cid, $from, $to, $channel)->groupByRaw($mk)->selectRaw("{$mk} AS k, SUM(order_items.total_cost) AS v")->get() as $r) {
            $add($r->k, 'cogs', $r->v);
        }
        foreach ($this->expenseRows($cid, $from, $to)->groupByRaw("DATE_FORMAT(expenses.expense_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(expenses.expense_date, '%Y-%m') AS k, SUM(amount) AS v")->get() as $r) {
            $add($r->k, 'expenses', $r->v);
        }
        foreach ($this->voidRows($cid, $from, $to)->groupByRaw("DATE_FORMAT(expenses.voided_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(expenses.voided_at, '%Y-%m') AS k, SUM(amount) AS v")->get() as $r) {
            $add($r->k, 'expenses', -(float) $r->v);
        }
        $fee = $this->fees->sql($cid);
        if ($fee !== '0') {
            foreach ($this->ledgerIn($cid, $from, $to, $channel)->groupByRaw("DATE_FORMAT(l.txn_date, '%Y-%m')")
                ->selectRaw("DATE_FORMAT(l.txn_date, '%Y-%m') AS k, SUM({$fee}) AS v")->get() as $r) {
                $add($r->k, 'paymentFees', $r->v);
            }
        }
        foreach (DB::table('finance_entries')->where('company_id', $cid)->whereIn('kind', ['income', 'expense'])
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->groupByRaw("DATE_FORMAT(date, '%Y-%m'), kind")->selectRaw("DATE_FORMAT(date, '%Y-%m') AS k, kind, SUM(amount) AS v")->get() as $r) {
            $add($r->k, $r->kind === 'income' ? 'otherIncome' : 'otherExpenses', $r->v);
        }
        ksort($m);
        $out = [];
        foreach ($m as $k => $v) {
            $gp = $v['revenue'] - $v['cogs'];
            $out[] = [
                'key' => $k,
                'label' => Carbon::createFromFormat('!Y-m', $k)->format('M Y'),
                'revenue' => round($v['revenue'], 2),
                'cogs' => round($v['cogs'], 2),
                'grossProfit' => round($gp, 2),
                'expenses' => round($v['expenses'], 2),
                'paymentFees' => round($v['paymentFees'], 2),
                'otherIncome' => round($v['otherIncome'], 2),
                'otherExpenses' => round($v['otherExpenses'], 2),
                'netProfit' => round($gp - $v['expenses'] - $v['paymentFees'] + $v['otherIncome'] - $v['otherExpenses'], 2),
            ];
        }

        return $out;
    }

    /** First local month with any finance activity (sales, expenses, payments, entries), or null. */
    public function firstActivityMonth(int $cid): ?string
    {
        $dates = array_filter([
            ($t = DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')->min('order_time')) ? Carbon::parse($t, 'UTC')->setTimezone(FinanceContext::tz($cid))->toDateString() : null,
            DB::table('expenses')->where('company_id', $cid)->whereNull('deleted_at')->min('expense_date'),
            DB::table('due_payments')->where('company_id', $cid)->min('payment_date'),
            DB::table('finance_entries')->where('company_id', $cid)->min('date'),
            DB::table('finance_transfers')->where('company_id', $cid)->min('date'),
            DB::table('vendor_payments')->where('company_id', $cid)->min('payment_date'),
        ]);
        if (!$dates) {
            return null;
        }

        return substr((string) min(array_map(fn ($d) => substr((string) $d, 0, 10), $dates)), 0, 7);
    }
}
