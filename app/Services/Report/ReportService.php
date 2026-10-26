<?php

namespace App\Services\Report;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only business reports (sales, purchases, stock valuation, dues aging, profit & loss).
 * Mirrors the finance/Reports.dc.html reference; every query is company scoped and grouped in SQL.
 *
 * Response: {type, period, subtitle, kpis[], chart, mixes[], tables[]} (see build* methods).
 */
class ReportService
{
    public const TYPES = ['sales', 'purchases', 'stock', 'dues', 'pnl'];

    private const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    private const ROW_CAP = 500;

    private const METHOD = [
        'cash' => 'Cash', 'card' => 'Card', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'Bank transfer',
        'cheque' => 'Cheque', 'credit' => 'On account', 'cod' => 'COD', 'cash on delivery' => 'COD',
        'online' => 'Online',
    ];
    private const CHANNEL = ['pos' => 'POS', 'manual' => 'Sales', 'sales' => 'Sales', 'storefront' => 'Online', 'online' => 'Online'];
    private const PO_STATUS = [
        'draft' => 'Draft', 'ordered' => 'Ordered', 'sent' => 'Ordered', 'received' => 'Received',
        'cancelled' => 'Cancelled', 'partial' => 'Partially received',
    ];
    private const BUCKETS = ['0–30 days', '31–60 days', '61–90 days', '90+ days'];

    public function report(int $cid, string $type, array $f): array
    {
        $from = !empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : null;
        $to = !empty($f['to']) ? Carbon::parse($f['to'])->startOfDay() : null;
        $usesRange = !in_array($type, ['stock', 'dues'], true);
        $win = $usesRange ? [$from, $to] : [null, null];

        $out = match ($type) {
            'sales' => $this->sales($cid, $win),
            'purchases' => $this->purchases($cid, $win),
            'stock' => $this->stock($cid),
            'dues' => $this->dues($cid),
            default => $this->pnl($cid, $win),
        };

        return [
            'type' => $type,
            'period' => $usesRange ? ['from' => $from?->toDateString(), 'to' => $to?->toDateString()] : null,
        ] + $out;
    }

    // ── Cell / KPI helpers ─────────────────────────────────────────────────────

    private function kpi(string $label, float|int|string $value, string $kind, string $sub, string $tone, string $subTone = 'muted', ?array $subMoney = null): array
    {
        $out = ['label' => $label, 'value' => $kind === 'text' ? $value : round((float) $value, 2), 'kind' => $kind,
            'sub' => $sub, 'tone' => $tone, 'subTone' => $subTone];
        // Optional structured sub line: [prefix, amount, suffix]; the client formats the amount with the company currency.
        if ($subMoney) {
            $out['subMoney'] = ['prefix' => $subMoney[0], 'amount' => round((float) $subMoney[1], 2), 'suffix' => $subMoney[2]];
        }
        return $out;
    }

    private function t($v, bool $bold = false): array
    {
        return ['t' => 'text', 'v' => (string) $v, 'bold' => $bold];
    }

    private function m($v, bool $bold = false): array
    {
        return ['t' => 'money', 'v' => round((float) $v, 2), 'bold' => $bold];
    }

    private function i($v, bool $bold = false): array
    {
        return ['t' => 'int', 'v' => (int) round((float) $v), 'bold' => $bold];
    }

    private function p($v, bool $bold = false): array
    {
        return ['t' => 'pct', 'v' => (int) round((float) $v), 'bold' => $bold];
    }

    private function table(string $title, string $empty, array $columns, array $rows, ?array $totalRow = null): array
    {
        return ['title' => $title, 'empty' => $empty, 'columns' => $columns, 'rows' => $rows,
            'totalRow' => $rows ? $totalRow : null];
    }

    private function money(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    private function pct(float $a, float $b): string
    {
        return $b > 0 ? round($a / $b * 100) . '%' : '0%';
    }

    private function plural(int $n, string $word): string
    {
        return $n . ' ' . $word . ($n === 1 ? '' : 's');
    }

    /** Top-6 mix, entries are [label => value] already summed, sorted desc here. */
    private function mix(string $title, string $kind, array $entries): array
    {
        arsort($entries);
        $items = [];
        foreach (array_slice($entries, 0, 6, true) as $label => $v) {
            $items[] = ['label' => (string) $label, 'value' => $kind === 'int' ? (int) $v : round((float) $v, 2)];
        }

        return ['title' => $title, 'kind' => $kind, 'items' => $items];
    }

    // ── Window / bucket helpers ────────────────────────────────────────────────

    private function applyWindow(Builder $q, string $col, array $win, bool $dateColumn = false): Builder
    {
        [$from, $to] = $win;
        if ($from) {
            $q->where($col, '>=', $dateColumn ? $from->toDateString() : $from->toDateTimeString());
        }
        if ($to) {
            $dateColumn
                ? $q->where($col, '<=', $to->toDateString())
                : $q->where($col, '<', $to->copy()->addDay()->toDateTimeString());
        }

        return $q;
    }

    private function sells(int $cid, array $win): Builder
    {
        $q = DB::table('sells')->where('sells.company_id', $cid)->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled');

        return $this->applyWindow($q, 'sells.order_time', $win);
    }

    /** Line items of non-cancelled sales in the window (cost / profit are taken from the lines, as in the reference). */
    private function lines(int $cid, array $win): Builder
    {
        return $this->applyWindow(
            DB::table('order_items')->join('sells', 'sells.id', '=', 'order_items.sell_id')
                ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled'),
            'sells.order_time',
            $win
        );
    }

    /** Line profit = (unit price - unit cost) x qty, i.e. quantity x unit_price - total_cost. */
    private const LINE_PROFIT = 'COALESCE(order_items.quantity * order_items.unit_price, 0) - COALESCE(order_items.total_cost, 0)';

    private function expenses(int $cid, array $win): Builder
    {
        $q = DB::table('expenses')->where('expenses.company_id', $cid)->whereNull('expenses.deleted_at');

        return $this->applyWindow($q, 'expenses.expense_date', $win, true);
    }

    /**
     * Chart buckets exactly as the reference: daily when span <= 31 days (and not P&L), otherwise
     * monthly capped to the last 12. Open bounds: from = earliest sale/PO/expense date, to = today.
     *
     * @return array{daily: bool, buckets: array<int, array{key: string, label: string, v: float}>}
     */
    private function buckets(int $cid, array $win, bool $pnl): array
    {
        [$from, $to] = $win;
        if (!$from) {
            $min = array_filter([
                DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')->where('status', '!=', 'Cancelled')->min(DB::raw('DATE(order_time)')),
                DB::table('purchase_orders')->where('company_id', $cid)->min(DB::raw('DATE(created_at)')),
                DB::table('expenses')->where('company_id', $cid)->whereNull('deleted_at')->min('expense_date'),
            ]);
            $from = $min ? Carbon::parse(min($min))->startOfDay() : now()->startOfDay();
        }
        $to = $to ?: now()->startOfDay();

        $span = max(1, (int) round($from->diffInDays($to, false)) + 1);
        $daily = !$pnl && $span <= 31;
        $buckets = [];
        if ($daily) {
            for ($n = 0; $n < $span; $n++) {
                $d = $from->copy()->addDays($n);
                $buckets[] = ['key' => $d->toDateString(), 'label' => $span <= 7 ? self::MON[$d->month - 1] . ' ' . $d->day : (string) $d->day, 'v' => 0.0];
            }
        } else {
            $y = $from->year;
            $mo = $from->month;
            $ey = $to->year;
            $em = $to->month;
            while (($y < $ey || ($y === $ey && $mo <= $em)) && count($buckets) < 240) {
                $buckets[] = ['key' => sprintf('%04d-%02d', $y, $mo), 'label' => self::MON[$mo - 1] . ($y !== $ey ? " '" . substr((string) $y, 2) : ''), 'v' => 0.0];
                if (++$mo > 12) {
                    $mo = 1;
                    $y++;
                }
            }
            $buckets = array_slice($buckets, -12);
        }

        return ['daily' => $daily, 'buckets' => $buckets];
    }

    private function bucketExpr(bool $daily, string $col): string
    {
        return $daily ? "DATE({$col})" : "DATE_FORMAT({$col}, '%Y-%m')";
    }

    /** @param array<string,float> $byKey bucket key => value */
    private function chart(string $title, bool $signed, array $buckets, array $byKey): array
    {
        $bars = [];
        $has = false;
        foreach ($buckets as $b) {
            $v = round((float) ($byKey[$b['key']] ?? 0), 2);
            $has = $has || $v != 0;
            $bars[] = ['label' => $b['label'], 'value' => $v];
        }

        return ['title' => $title, 'signed' => $signed, 'bars' => $has ? $bars : []];
    }

    // ── Sales ──────────────────────────────────────────────────────────────────

    private function sales(int $cid, array $win): array
    {
        $a = $this->sells($cid, $win)->selectRaw(
            'COUNT(*) as orders, COALESCE(SUM(amount),0) as rev, COALESCE(SUM(discount),0) as disc, COALESCE(SUM(due_amount),0) as due, COALESCE(SUM(tax_amount),0) as tax'
        )->first();
        $orders = (int) $a->orders;
        $rev = (float) $a->rev;
        $gp = (float) ($this->lines($cid, $win)->selectRaw('COALESCE(SUM(' . self::LINE_PROFIT . '), 0) as gp')->value('gp') ?? 0);
        $due = (float) $a->due;

        // Tax collected is the stored sells.tax_amount (revenue already is the invoice total, tax included).
        $tax = (float) $a->tax;
        $kpis = [
            $this->kpi('Revenue', $rev, 'money', 'Invoice totals', 'teal'),
            $this->kpi('Orders', $orders, 'int', $due > 0 ? $this->money($due) . ' still due' : 'All paid', 'blue', 'muted', $due > 0 ? ['', $due, ' still due'] : null),
            $this->kpi('Avg. order value', $orders ? $rev / $orders : 0, 'money', 'Revenue per order', 'purple'),
            $this->kpi('Discounts', (float) $a->disc, 'money', 'Given in this period', 'amber'),
            $this->kpi('Tax collected', $tax, 'money', 'Included in invoice totals', 'blue'),
            $this->kpi('Gross profit', $gp, 'money', $rev ? $this->pct($gp, $rev) . ' of revenue' : 'No sales yet', 'teal'),
        ];

        $bk = $this->buckets($cid, $win, false);
        $expr = $this->bucketExpr($bk['daily'], 'sells.order_time');
        $byKey = $this->sells($cid, $win)->groupByRaw($expr)->selectRaw("{$expr} as k, SUM(amount) as v")->pluck('v', 'k')->all();
        $chart = $this->chart($bk['daily'] ? 'Revenue per day' : 'Revenue per month', false, $bk['buckets'], $byKey);

        $methods = [];
        foreach ($this->sells($cid, $win)->groupByRaw("LOWER(TRIM(COALESCE(method, '')))")
            ->selectRaw("LOWER(TRIM(COALESCE(method, ''))) as k, MAX(method) as raw, SUM(amount) as v")->get() as $r) {
            $label = $r->k === '' ? 'Other' : (self::METHOD[$r->k] ?? (string) $r->raw);
            $methods[$label] = ($methods[$label] ?? 0) + (float) $r->v;
        }
        $channels = [];
        foreach ($this->sells($cid, $win)->groupByRaw("LOWER(TRIM(COALESCE(source, '')))")
            ->selectRaw("LOWER(TRIM(COALESCE(source, ''))) as k, SUM(amount) as v")->get() as $r) {
            $label = $r->k === '' ? 'Other' : (self::CHANNEL[$r->k] ?? ucfirst($r->k));
            $channels[$label] = ($channels[$label] ?? 0) + (float) $r->v;
        }

        $items = DB::table('order_items')->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled');
        $this->applyWindow($items, 'sells.order_time', $win);
        $prod = $items->groupBy('order_items.product_id')->selectRaw(
            'MAX(order_items.product_name) as name, SUM(order_items.quantity) as qty, SUM(order_items.total_price) as rev, SUM(' . self::LINE_PROFIT . ') as profit'
        );
        $topQty = (clone $prod)->orderByDesc('qty')->limit(10)->get();
        $topRev = (clone $prod)->orderByDesc('rev')->limit(10)->get();
        $prow = fn($rows) => $rows->values()->map(fn($r, $n) => [
            $this->t(($n + 1) . '. ' . ($r->name ?: 'Unknown product'), true), $this->i($r->qty), $this->m($r->rev), $this->m($r->profit),
        ])->all();
        $pcols = ['Product', 'Qty sold', 'Revenue', 'Gross profit'];

        $cust = $this->sells($cid, $win)->whereNotNull('sells.customer_id')
            ->leftJoin('customers', 'customers.id', '=', 'sells.customer_id')
            ->groupBy('sells.customer_id')
            ->selectRaw("COALESCE(MAX(customers.name), MAX(sells.customer_name), 'Customer') as name, COUNT(*) as orders, SUM(sells.amount) as rev, SUM(sells.due_amount) as due")
            ->orderByDesc('rev')->limit(10)->get();

        return [
            'subtitle' => 'Revenue, profit and best sellers. Cancelled sales are excluded.',
            'kpis' => $kpis,
            'chart' => $chart,
            'mixes' => [$this->mix('By payment method', 'money', $methods), $this->mix('By channel', 'money', $channels)],
            'tables' => [
                $this->table('Top 10 products by quantity', 'No products sold in this period.', $pcols, $prow($topQty)),
                $this->table('Top 10 products by revenue', 'No products sold in this period.', $pcols, $prow($topRev)),
                $this->table('Top customers', 'No customer sales in this period (walk-in sales are not listed).', ['Customer', 'Orders', 'Revenue', 'Due'],
                    $cust->map(fn($c) => [$this->t($c->name, true), $this->i($c->orders), $this->m($c->rev), $this->m($c->due)])->all()),
            ],
        ];
    }

    // ── Purchases ──────────────────────────────────────────────────────────────

    private function pos(int $cid, array $win): Builder
    {
        $q = DB::table('purchase_orders as po')->where('po.company_id', $cid);

        return $this->applyWindow($q, 'po.created_at', $win);
    }

    private function purchases(int $cid, array $win): array
    {
        $all = (int) $this->pos($cid, $win)->count();
        $live = fn() => $this->pos($cid, $win)->where('po.status', '!=', 'cancelled');

        $a = $live()->selectRaw(
            "COUNT(*) as n, COALESCE(SUM(total_amount),0) as tot, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(due_amount),0) as due, COALESCE(SUM(CASE WHEN po.status = 'received' THEN 1 ELSE 0 END),0) as recv"
        )->first();
        $n = (int) $a->n;
        $tot = (float) $a->tot;
        $paid = (float) $a->paid;
        $due = (float) $a->due;
        $recv = (int) $a->recv;
        $cancelled = $all - $n;

        $kpis = [
            $this->kpi('Purchase orders', $n, 'int', $cancelled ? $cancelled . ' cancelled' : 'Excluding cancelled', 'blue'),
            $this->kpi('Total value', $tot, 'money', $n ? 'Avg ' . $this->money($tot / $n) : 'No orders yet', 'teal', 'muted', $n ? ['Avg ', $tot / $n, ''] : null),
            $this->kpi('Paid', $paid, 'money', $tot ? $this->pct($paid, $tot) . ' of total' : '—', 'teal'),
            $this->kpi('Due to suppliers', $due, 'money', $due ? 'Unpaid balance' : 'All settled', 'red', $due ? 'red' : 'muted'),
            $this->kpi('Received', $recv, 'int', ($n - $recv) . ' not yet received', 'purple'),
        ];

        $bk = $this->buckets($cid, $win, false);
        $expr = $this->bucketExpr($bk['daily'], 'po.created_at');
        $byKey = $live()->groupByRaw($expr)->selectRaw("{$expr} as k, SUM(total_amount) as v")->pluck('v', 'k')->all();
        $chart = $this->chart($bk['daily'] ? 'Purchases per day' : 'Purchases per month', false, $bk['buckets'], $byKey);

        $statuses = [];
        foreach ($this->pos($cid, $win)->groupBy('po.status')->selectRaw('po.status as s, COUNT(*) as c')->get() as $r) {
            $label = self::PO_STATUS[strtolower((string) $r->s)] ?? ($r->s ?: 'Other');
            $statuses[$label] = ($statuses[$label] ?? 0) + (int) $r->c;
        }

        $sup = $live()->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')->groupBy('po.vendor_id')
            ->selectRaw("COALESCE(MAX(v.name), 'Unknown') as name, COUNT(*) as n, SUM(po.total_amount) as total, SUM(po.paid_amount) as paid, SUM(po.due_amount) as due")
            ->orderByDesc('total')->get();
        $supMix = [];
        foreach ($sup as $s) {
            $supMix[$s->name] = ($supMix[$s->name] ?? 0) + (float) $s->total;
        }

        $list = $this->pos($cid, $win)->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->orderByDesc('po.created_at')->orderByDesc('po.id')->limit(self::ROW_CAP)
            ->get(['po.po_number', 'po.id', 'po.created_at', 'po.status', 'po.total_amount', 'po.due_amount', 'v.name as vendor']);

        return [
            'subtitle' => 'What was ordered from suppliers and how much is still owed.',
            'kpis' => $kpis,
            'chart' => $chart,
            'mixes' => [$this->mix('Status mix (orders)', 'int', $statuses), $this->mix('Top suppliers by value', 'money', $supMix)],
            'tables' => [
                $this->table('By supplier', 'No purchase orders in this period.', ['Supplier', 'Orders', 'Total', 'Paid', 'Due'],
                    $sup->map(fn($s) => [$this->t($s->name, true), $this->i($s->n), $this->m($s->total), $this->m($s->paid), $this->m($s->due)])->all()),
                $this->table('Purchase orders', 'No purchase orders in this period.', ['PO', 'Date', 'Supplier', 'Status', 'Total', 'Due'],
                    $list->map(fn($p) => [
                        $this->t($p->po_number ?: $p->id, true), $this->t(substr((string) $p->created_at, 0, 10)), $this->t($p->vendor ?: '—'),
                        $this->t(self::PO_STATUS[strtolower((string) $p->status)] ?? ($p->status ?: 'Other')), $this->m($p->total_amount), $this->m($p->due_amount),
                    ])->all()),
            ],
        ];
    }

    // ── Stock valuation ────────────────────────────────────────────────────────

    /**
     * Per-location stock rows (pid, loc, qty, cost, price) as a SQL fragment. Same storage model as
     * InventoryOverviewService: real variants and the "Default" variant of simple products keep rows in
     * variant_inventory; legacy stock (variant/product .stock) sits at products.location_id when there are none.
     * Bundles are excluded (virtual stock), like the dashboard.
     */
    private function stockRowsSql(int $cid): string
    {
        $base = "products p WHERE p.company_id = {$cid} AND p.deleted_at IS NULL AND p.is_bundle = 0";
        $hasReal = "EXISTS (SELECT 1 FROM product_variants rv WHERE rv.product_id = p.id AND rv.deleted_at IS NULL AND rv.name <> 'Default')";
        $vcost = 'COALESCE(NULLIF(v.cost_price, 0), p.cost_price, 0)';
        $vprice = 'COALESCE(NULLIF(v.price, 0), p.price, 0)';

        return "
          SELECT p.id AS pid, vi.location_id AS loc, vi.quantity AS qty, {$vcost} AS cost, {$vprice} AS price
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL AND v.name <> 'Default'
            JOIN variant_inventory vi ON vi.variant_id = v.id
           WHERE p.company_id = {$cid} AND p.deleted_at IS NULL AND p.is_bundle = 0
          UNION ALL
          SELECT p.id, p.location_id, v.stock, {$vcost}, {$vprice}
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL AND v.name <> 'Default'
           WHERE p.company_id = {$cid} AND p.deleted_at IS NULL AND p.is_bundle = 0 AND p.location_id IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM variant_inventory x WHERE x.variant_id = v.id)
          UNION ALL
          SELECT p.id, vi.location_id, vi.quantity, COALESCE(p.cost_price, 0), COALESCE(p.price, 0)
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.deleted_at IS NULL AND v.name = 'Default'
            JOIN variant_inventory vi ON vi.variant_id = v.id
           WHERE p.company_id = {$cid} AND p.deleted_at IS NULL AND p.is_bundle = 0 AND NOT {$hasReal}
          UNION ALL
          SELECT p.id, p.location_id, p.stock, COALESCE(p.cost_price, 0), COALESCE(p.price, 0)
            FROM {$base} AND p.location_id IS NOT NULL AND NOT {$hasReal}
             AND NOT EXISTS (SELECT 1 FROM product_variants dv JOIN variant_inventory dvi ON dvi.variant_id = dv.id
                              WHERE dv.product_id = p.id AND dv.deleted_at IS NULL AND dv.name = 'Default')";
    }

    private function stock(int $cid): array
    {
        $rows = $this->stockRowsSql($cid);

        $tot = DB::selectOne("SELECT COALESCE(SUM(qty),0) AS units, COALESCE(SUM(qty*cost),0) AS cv, COALESCE(SUM(qty*price),0) AS rv FROM ({$rows}) inv WHERE inv.qty > 0");
        $units = (int) $tot->units;
        $cv = (float) $tot->cv;
        $rv = (float) $tot->rv;

        // Per product totals over all locations (reference: low = 0 < q <= reorder, out = q <= 0).
        $prod = "SELECT p.id, p.name, p.sku, p.price, p.cost_price,
                        COALESCE(NULLIF(p.reorder_point, 0), (SELECT SUM(rv.reorder_point) FROM product_variants rv WHERE rv.product_id = p.id AND rv.deleted_at IS NULL AND rv.name <> 'Default'), 0) AS reorder,
                        COALESCE((SELECT SUM(inv.qty) FROM ({$rows}) inv WHERE inv.pid = p.id), 0) AS q
                   FROM products p WHERE p.company_id = {$cid} AND p.deleted_at IS NULL AND p.is_bundle = 0";
        $c = DB::selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(t.q <= 0),0) AS out_n, COALESCE(SUM(t.q > 0 AND t.q <= t.reorder),0) AS low_n FROM ({$prod}) t");
        $lowRows = DB::select("SELECT * FROM ({$prod}) t WHERE t.q > 0 AND t.q <= t.reorder ORDER BY t.name LIMIT " . self::ROW_CAP);
        $outRows = DB::select("SELECT * FROM ({$prod}) t WHERE t.q <= 0 ORDER BY t.name LIMIT " . self::ROW_CAP);
        $names = fn(array $r) => implode(', ', array_map(fn($x) => $x->name, array_slice($r, 0, 2)));
        $low = (int) $c->low_n;
        $out = (int) $c->out_n;

        $kpis = [
            $this->kpi('Units on hand', $units, 'int', $this->plural((int) $c->n, 'product'), 'blue'),
            $this->kpi('Stock value (cost)', $cv, 'money', 'What it cost you', 'teal'),
            $this->kpi('Stock value (retail)', $rv, 'money', 'At selling price', 'purple'),
            $this->kpi('Potential margin', $rv - $cv, 'money', $rv ? $this->pct($rv - $cv, $rv) . ' of retail' : '—', 'teal'),
            $this->kpi('Low stock', $low, 'int', $low ? $names($lowRows) : 'Stock is healthy', 'amber', $low ? 'amber' : 'muted'),
            $this->kpi('Out of stock', $out, 'int', $out ? $names($outRows) : 'Nothing is out', 'red', $out ? 'red' : 'muted'),
        ];

        $byLoc = [];
        foreach (DB::select("SELECT COALESCE(l.name, '—') AS name, SUM(inv.qty * inv.cost) AS cv FROM ({$rows}) inv LEFT JOIN locations l ON l.id = inv.loc WHERE inv.qty > 0 GROUP BY inv.loc, l.name") as $r) {
            $byLoc[$r->name] = ($byLoc[$r->name] ?? 0) + (float) $r->cv;
        }

        $val = DB::select("SELECT p.name, COALESCE(l.name, '—') AS loc, SUM(inv.qty) AS q, SUM(inv.qty * inv.cost) AS cv, SUM(inv.qty * inv.price) AS rv
                             FROM ({$rows}) inv JOIN products p ON p.id = inv.pid LEFT JOIN locations l ON l.id = inv.loc
                            WHERE inv.qty > 0 GROUP BY inv.pid, inv.loc, p.name, l.name ORDER BY cv DESC LIMIT " . self::ROW_CAP);

        return [
            'subtitle' => 'Current stock on hand valued at cost and at retail price. Not affected by the date range.',
            'kpis' => $kpis,
            'chart' => null,
            'mixes' => [$this->mix('Cost value by location', 'money', $byLoc)],
            'tables' => [
                $this->table('Valuation by product and location', 'No stock on hand yet.', ['Product', 'Location', 'Qty', 'Cost value', 'Retail value'],
                    array_map(fn($r) => [$this->t($r->name, true), $this->t($r->loc), $this->i($r->q), $this->m($r->cv), $this->m($r->rv)], $val),
                    [$this->t('Total', true), $this->t(''), $this->i($units, true), $this->m($cv, true), $this->m($rv, true)]),
                $this->table('Low stock', 'No products are low on stock.', ['Product', 'SKU', 'On hand', 'Reorder at', 'Cost value'],
                    array_map(fn($r) => [$this->t($r->name, true), $this->t($r->sku ?: '—'), $this->i($r->q), $this->i($r->reorder), $this->m($r->q * (float) $r->cost_price)], $lowRows)),
                $this->table('Out of stock', 'Nothing is out of stock.', ['Product', 'SKU', 'On hand', 'Reorder at', 'Retail price'],
                    array_map(fn($r) => [$this->t($r->name, true), $this->t($r->sku ?: '—'), $this->i(0), $this->i($r->reorder), $this->m($r->price)], $outRows)),
            ],
        ];
    }

    // ── Receivables & payables ─────────────────────────────────────────────────

    /** SQL CASE (0..3) for the age in days of a date expression, vs. today. */
    private function ageCase(string $dateExpr): string
    {
        $d = "DATEDIFF(CURDATE(), DATE({$dateExpr}))";

        return "CASE WHEN {$d} <= 30 THEN 0 WHEN {$d} <= 60 THEN 1 WHEN {$d} <= 90 THEN 2 ELSE 3 END";
    }

    /** @return array{0: array<int,array>, 1: array<int,float>, 2: float} rows (name,a[4],total), column sums, grand total */
    private function agingTable(array $parties): array
    {
        uasort($parties, fn($x, $y) => $y['total'] <=> $x['total']);
        $rows = array_values(array_filter($parties, fn($r) => $r['total'] > 0));
        $sum = [0.0, 0.0, 0.0, 0.0];
        $tot = 0.0;
        foreach ($rows as $r) {
            for ($k = 0; $k < 4; $k++) {
                $sum[$k] += $r['a'][$k];
            }
            $tot += $r['total'];
        }

        return [$rows, $sum, $tot];
    }

    private function agingRows(array $rows, array $sum, float $tot): array
    {
        $body = array_map(fn($r) => [$this->t($r['name'], true), $this->m($r['a'][0]), $this->m($r['a'][1]), $this->m($r['a'][2]), $this->m($r['a'][3]), $this->m($r['total'], true)], $rows);
        $total = [$this->t('Total', true), $this->m($sum[0], true), $this->m($sum[1], true), $this->m($sum[2], true), $this->m($sum[3], true), $this->m($tot, true)];

        return [$body, $total];
    }

    /**
     * Receivables: open sells (due_amount > 0, not cancelled) aged by order_time, grouped by customer
     * (walk-in sells without a customer are one "Walk-in customers" row, so the total equals the dashboard).
     * Payables: open POs (due_amount > 0, not cancelled/draft - same as the dashboard) aged by created_at, grouped
     * by vendor. vendors.opening_balance > 0 counts as one document aged by the vendor's created_at date
     * (it has no own date), so vendors with only an opening balance also appear.
     */
    private function dues(int $cid): array
    {
        $rec = [];
        $age = $this->ageCase('sells.order_time');
        $q = DB::table('sells')->leftJoin('customers', 'customers.id', '=', 'sells.customer_id')
            ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled')
            ->where('sells.due_amount', '>', 0)
            ->groupByRaw('COALESCE(sells.customer_id, 0), ' . $age)
            ->selectRaw("COALESCE(sells.customer_id, 0) as pid, COALESCE(MAX(customers.name), MAX(sells.customer_name), 'Walk-in customers') as name, {$age} as b, SUM(sells.due_amount) as due");
        foreach ($q->get() as $r) {
            $rec[$r->pid] ??= ['name' => $r->pid ? $r->name : 'Walk-in customers', 'a' => [0.0, 0.0, 0.0, 0.0], 'total' => 0.0];
            $rec[$r->pid]['a'][(int) $r->b] += (float) $r->due;
            $rec[$r->pid]['total'] += (float) $r->due;
        }

        $pay = [];
        $age = $this->ageCase('po.created_at');
        $q = DB::table('purchase_orders as po')->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->where('po.company_id', $cid)->whereNotIn('po.status', ['cancelled', 'draft'])->where('po.due_amount', '>', 0)
            ->groupByRaw("po.vendor_id, {$age}")
            ->selectRaw("po.vendor_id as pid, COALESCE(MAX(v.name), 'Unknown') as name, {$age} as b, SUM(po.due_amount) as due");
        foreach ($q->get() as $r) {
            $pay[$r->pid] ??= ['name' => $r->name, 'a' => [0.0, 0.0, 0.0, 0.0], 'total' => 0.0];
            $pay[$r->pid]['a'][(int) $r->b] += (float) $r->due;
            $pay[$r->pid]['total'] += (float) $r->due;
        }
        $age = $this->ageCase('vendors.created_at');
        $ob = DB::table('vendors')->where('company_id', $cid)->whereNull('deleted_at')->where('opening_balance', '>', 0)
            ->selectRaw("id as pid, name, {$age} as b, opening_balance as due")->get();
        foreach ($ob as $r) {
            $pay[$r->pid] ??= ['name' => $r->name, 'a' => [0.0, 0.0, 0.0, 0.0], 'total' => 0.0];
            $pay[$r->pid]['a'][(int) $r->b] += (float) $r->due;
            $pay[$r->pid]['total'] += (float) $r->due;
        }

        [$recRows, $rA, $rT] = $this->agingTable($rec);
        [$payRows, $pA, $pT] = $this->agingTable($pay);

        $kpis = [
            $this->kpi('Receivable', $rT, 'money', $this->plural(count($recRows), 'customer') . ' owe you', 'amber'),
            $this->kpi('Over 60 days', $rA[2] + $rA[3], 'money', 'Receivable, needs chasing', 'red', ($rA[2] + $rA[3]) > 0 ? 'red' : 'muted'),
            $this->kpi('Payable', $pT, 'money', 'To ' . $this->plural(count($payRows), 'supplier'), 'blue'),
            $this->kpi('Over 60 days', $pA[2] + $pA[3], 'money', 'Payable, overdue', 'red', ($pA[2] + $pA[3]) > 0 ? 'red' : 'muted'),
            $this->kpi('Net position', $rT - $pT, 'money', $rT >= $pT ? 'You are owed more than you owe' : 'You owe more than you are owed', 'teal'),
        ];

        $aMix = function (string $title, array $A) {
            $e = [];
            foreach ($A as $k => $v) {
                if ($v > 0) {
                    $e[self::BUCKETS[$k]] = $v;
                }
            }
            $items = [];
            foreach ($e as $l => $v) {
                $items[] = ['label' => $l, 'value' => round($v, 2)];
            }
            usort($items, fn($x, $y) => $y['value'] <=> $x['value']);

            return ['title' => $title, 'kind' => 'money', 'items' => array_slice($items, 0, 6)];
        };

        [$recBody, $recTotal] = $this->agingRows($recRows, $rA, $rT);
        [$payBody, $payTotal] = $this->agingRows($payRows, $pA, $pT);

        return [
            'subtitle' => 'Open balances with customers and suppliers, aged from the document date. Not affected by the date range.',
            'kpis' => $kpis,
            'chart' => null,
            'mixes' => [$aMix('Receivable aging', $rA), $aMix('Payable aging', $pA)],
            'tables' => [
                $this->table('Receivables by customer', 'No customer owes you anything.', array_merge(['Customer'], self::BUCKETS, ['Total due']), $recBody, $recTotal),
                $this->table('Payables by supplier', 'You do not owe any supplier.', array_merge(['Supplier'], self::BUCKETS, ['Total due']), $payBody, $payTotal),
            ],
        ];
    }

    // ── Profit & loss ──────────────────────────────────────────────────────────

    private function pnl(int $cid, array $win): array
    {
        // sells has no tax column, so revenue (excluding tax) equals sells.amount.
        $s = $this->sells($cid, $win)->selectRaw('COALESCE(SUM(amount),0) as rev')->first();
        $s->cogs = $this->lines($cid, $win)->sum('order_items.total_cost');
        $e = $this->expenses($cid, $win)->selectRaw('COALESCE(SUM(amount),0) as ex, COUNT(*) as n')->first();
        $rev = (float) $s->rev;
        $cogs = (float) $s->cogs;
        $ex = (float) $e->ex;
        $gp = $rev - $cogs;
        $net = $gp - $ex;
        $en = (int) $e->n;

        $kpis = [
            $this->kpi('Revenue', $rev, 'money', 'Excluding tax', 'teal'),
            $this->kpi('Cost of goods', $cogs, 'money', 'Cost of items sold', 'blue'),
            $this->kpi('Gross profit', $gp, 'money', $rev ? $this->pct($gp, $rev) . ' margin' : '—', 'teal'),
            $this->kpi('Expenses', $ex, 'money', $this->plural($en, 'record'), 'amber'),
            $this->kpi('Net profit', $net, 'money', $rev ? round($net / $rev * 100) . '% net margin' : '—', $net < 0 ? 'red' : 'purple', $net < 0 ? 'red' : 'muted'),
        ];

        $bk = $this->buckets($cid, $win, true)['buckets'];
        $mon = [];
        foreach ($bk as $b) {
            $mon[$b['key']] = ['rev' => 0.0, 'cogs' => 0.0, 'ex' => 0.0];
        }
        foreach ($this->sells($cid, $win)->groupByRaw("DATE_FORMAT(sells.order_time, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(sells.order_time, '%Y-%m') as k, SUM(amount) as rev")->get() as $r) {
            if (isset($mon[$r->k])) {
                $mon[$r->k]['rev'] += (float) $r->rev;
            }
        }
        foreach ($this->lines($cid, $win)->groupByRaw("DATE_FORMAT(sells.order_time, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(sells.order_time, '%Y-%m') as k, SUM(order_items.total_cost) as cogs")->get() as $r) {
            if (isset($mon[$r->k])) {
                $mon[$r->k]['cogs'] += (float) $r->cogs;
            }
        }
        foreach ($this->expenses($cid, $win)->groupByRaw("DATE_FORMAT(expenses.expense_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(expenses.expense_date, '%Y-%m') as k, SUM(amount) as ex")->get() as $r) {
            if (isset($mon[$r->k])) {
                $mon[$r->k]['ex'] += (float) $r->ex;
            }
        }
        $byKey = [];
        $rows = [];
        foreach ($mon as $key => $m) {
            $g = $m['rev'] - $m['cogs'];
            $n = $g - $m['ex'];
            $byKey[$key] = $n;
            if ($m['rev'] || $m['cogs'] || $m['ex']) {
                $rows[] = [
                    $this->t(self::MON[(int) substr($key, 5, 2) - 1] . ' ' . substr($key, 0, 4), true), $this->m($m['rev']), $this->m($m['cogs']), $this->m($g),
                    $this->m($m['ex']), $this->m($n, true), $m['rev'] ? $this->p($n / $m['rev'] * 100) : $this->t('—'),
                ];
            }
        }

        $cats = [];
        foreach ($this->expenses($cid, $win)->leftJoin('expense_categories as c', 'c.id', '=', 'expenses.expense_category_id')
            ->groupBy('expenses.expense_category_id')->selectRaw("COALESCE(MAX(c.name), 'Uncategorised') as name, SUM(expenses.amount) as v")->get() as $r) {
            $cats[$r->name] = ($cats[$r->name] ?? 0) + (float) $r->v;
        }

        return [
            'subtitle' => 'Revenue less cost of goods sold and recorded expenses, month by month.',
            'kpis' => $kpis,
            'chart' => $this->chart('Net profit per month', true, $bk, $byKey),
            'mixes' => [$this->mix('Expenses by category', 'money', $cats)],
            'tables' => [
                $this->table('Profit & loss by month', 'No sales or expenses in this period.', ['Month', 'Revenue', 'COGS', 'Gross profit', 'Expenses', 'Net profit', 'Margin'], $rows,
                    [$this->t('Total', true), $this->m($rev, true), $this->m($cogs, true), $this->m($gp, true), $this->m($ex, true), $this->m($net, true),
                        $rev ? $this->p($net / $rev * 100, true) : $this->t('—', true)]),
            ],
        ];
    }
}
