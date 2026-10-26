<?php

namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregations for the dashboard. One public method per dashboard section so a
 * failure in one section never affects the others. All queries are company scoped.
 */
class DashboardService
{
    private const DEFAULT_LEAD_TIME_DAYS = 7;
    private const DEFAULT_SAFETY_DAYS = 3;

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Company timezone (company_settings.timezone), falling back to Asia/Dhaka. */
    private function tz(int $cid): string
    {
        static $cache = [];
        if (!isset($cache[$cid])) {
            $tz = DB::table('company_settings')->where('company_id', $cid)->value('timezone');
            $cache[$cid] = ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) ? $tz : 'Asia/Dhaka';
        }

        return $cache[$cid];
    }

    /**
     * Range boundaries in the company timezone (queries convert them to UTC in inRange()).
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon} from, to, prevFrom, prevTo
     */
    private function range(array $f, int $cid): array
    {
        $tz = $this->tz($cid);
        $to = !empty($f['to']) ? Carbon::parse($f['to'], $tz)->endOfDay() : now($tz)->endOfDay();
        $from = !empty($f['from']) ? Carbon::parse($f['from'], $tz)->startOfDay() : $to->copy()->startOfYear();
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        $days = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [$from, $to, $prevFrom, $prevTo];
    }

    /** Company sales; with a location, sales without one (e.g. online orders) are kept, like a shared pool. */
    private function sells(int $cid, ?int $loc = null): Builder
    {
        $q = DB::table('sells')->where('sells.company_id', $cid)->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled');
        if ($loc) {
            $q->where(fn($w) => $w->whereNull('sells.location_id')->orWhere('sells.location_id', $loc));
        }

        return $q;
    }

    private function loc(array $f): ?int
    {
        return !empty($f['location_id']) ? (int) $f['location_id'] : null;
    }

    private function inRange(Builder $q, Carbon $from, Carbon $to, string $col = 'sells.order_time'): Builder
    {
        return $q->whereBetween($col, [$from->copy()->utc(), $to->copy()->utc()]);
    }

    private function products(int $cid, ?int $locationId = null): Builder
    {
        $q = DB::table('products')
            ->where('products.company_id', $cid)
            ->whereNull('products.deleted_at')
            ->where('products.is_bundle', false);
        if ($locationId) {
            $q->where('products.location_id', $locationId);
        }

        return $q;
    }

    /** Hourly for a single day, daily for short ranges, monthly otherwise. */
    private function bucketMode(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return 'hour';
        }

        return $from->diffInDays($to) <= 62 ? 'day' : 'month';
    }

    /** @return string[] bucket keys in order */
    private function bucketKeys(Carbon $from, Carbon $to, string $mode): array
    {
        if ($mode === 'hour') {
            return array_map(fn($h) => sprintf('%02d', $h), range(0, 23));
        }
        $keys = [];
        $cursor = $mode === 'day' ? $from->copy()->startOfDay() : $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $keys[] = $mode === 'day' ? $cursor->format('Y-m-d') : $cursor->format('Y-m');
            $mode === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return $keys;
    }

    /** Bucket SQL in the company timezone (fixed UTC offset, so no MySQL timezone tables are needed). */
    private function bucketExpr(string $mode, Carbon $from, string $col = 'sells.order_time'): string
    {
        $local = "CONVERT_TZ({$col}, '+00:00', '" . $from->format('P') . "')";

        return match ($mode) {
            'hour' => "LPAD(HOUR({$local}), 2, '0')",
            'day' => "DATE({$local})",
            default => "DATE_FORMAT({$local}, '%Y-%m')",
        };
    }

    private function pctDelta(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function monthLabels(): array
    {
        return [now()->format('M Y'), now()->format('Y-m')];
    }

    /** Units sold per product in the last N days (product_id => qty). */
    private function unitsSold(int $cid, int $days, ?int $loc = null): array
    {
        return DB::table('order_items')
            ->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $cid)
            ->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled')
            ->when($loc, fn($q) => $q->where(fn($w) => $w->whereNull('sells.location_id')->orWhere('sells.location_id', $loc)))
            ->where('sells.order_time', '>=', now()->subDays($days))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as product_id, SUM(order_items.quantity) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn($v) => (float) $v)
            ->all();
    }

    private function deadStock(int $cid, ?int $locationId): array
    {
        $row = $this->products($cid, $locationId)
            ->where('products.stock', '>', 0)
            ->whereNotExists(function ($q) use ($cid) {
                $q->select(DB::raw(1))
                    ->from('order_items')
                    ->join('sells', 'sells.id', '=', 'order_items.sell_id')
                    ->whereColumn('order_items.product_id', 'products.id')
                    ->where('sells.company_id', $cid)
                    ->whereNull('sells.deleted_at')
                    ->where('sells.status', '!=', 'Cancelled')
                    ->where('sells.order_time', '>=', now()->subDays(90));
            })
            ->selectRaw('COUNT(*) as skus, COALESCE(SUM(COALESCE(products.cost_price, 0) * products.stock), 0) as value')
            ->first();

        return ['skus' => (int) $row->skus, 'value' => (float) $row->value];
    }

    private function inventoryTotals(int $cid, ?int $locationId): array
    {
        $row = $this->products($cid, $locationId)
            ->selectRaw('COUNT(*) as skus, COALESCE(SUM(products.stock), 0) as units, COALESCE(SUM(COALESCE(products.cost_price, 0) * products.stock), 0) as value')
            ->first();

        return ['skus' => (int) $row->skus, 'units' => (int) $row->units, 'value' => (float) $row->value];
    }

    // ── 3. Needs attention ─────────────────────────────────────────────────────

    public function alerts(int $cid, array $f): array
    {
        $loc = $this->loc($f);

        $pending = $this->sells($cid, $loc)->where('status', 'Pending');
        $pendingCount = (clone $pending)->count();
        $oldest = (clone $pending)->min('order_time');

        $stock = $this->products($cid, $loc)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN products.stock <= 0 THEN 1 ELSE 0 END), 0) as out_of_stock,
                COALESCE(SUM(CASE WHEN products.stock > 0 AND products.reorder_point > 0 AND products.stock <= products.reorder_point THEN 1 ELSE 0 END), 0) as below_reorder
            ')
            ->first();

        $salary = $this->salaryStatus($cid);

        return [
            'pendingOrders' => [
                'count' => $pendingCount,
                'oldestOrderTime' => $oldest ? Carbon::parse($oldest)->toIso8601String() : null,
            ],
            'stock' => [
                'outOfStock' => (int) $stock->out_of_stock,
                'belowReorder' => (int) $stock->below_reorder,
            ],
            'salaries' => $salary,
        ];
    }

    /**
     * Salary position from the payslip rows (salary_payments). `budget`/`paid` are this month's
     * payslips; `unpaid`/`payslips` cover every unpaid payslip (like the Salary page's "due" rows).
     */
    private function salaryStatus(int $cid): array
    {
        $base = fn() => DB::table('salary_payments')
            ->join('staff', 'staff.id', '=', 'salary_payments.staff_id')
            ->where('staff.company_id', $cid)
            ->whereNull('salary_payments.deleted_at');

        $month = $base()->whereIn('salary_payments.month', $this->monthLabels())
            ->selectRaw("COUNT(*) as n, COALESCE(SUM(salary_payments.amount), 0) as total,
                COALESCE(SUM(CASE WHEN LOWER(salary_payments.status) = 'paid' THEN salary_payments.amount ELSE 0 END), 0) as paid")
            ->first();

        $dueRows = $base()->whereRaw("LOWER(salary_payments.status) != 'paid'")
            ->get(['salary_payments.month', 'salary_payments.amount', 'salary_payments.paid_amount']);
        $unpaid = 0.0;
        $earliest = null;
        foreach ($dueRows as $r) {
            $unpaid += max(0, (float) $r->amount - min((float) $r->paid_amount, (float) $r->amount));
            try {
                $end = preg_match('/^\d{4}-\d{2}$/', (string) $r->month)
                    ? Carbon::createFromFormat('Y-m-d', $r->month . '-01')->endOfMonth()
                    : Carbon::parse('1 ' . $r->month)->endOfMonth();
                if (!$earliest || $end->lt($earliest)) {
                    $earliest = $end;
                }
            } catch (\Throwable) {
            }
        }

        $dueDate = now()->endOfMonth();

        return [
            'month' => now()->format('F Y'),
            'budget' => (float) $month->total,
            'paid' => (float) $month->paid,
            'unpaid' => $unpaid,
            'payslips' => $dueRows->count(),
            'generated' => (int) $month->n,
            'dueDate' => $dueDate->toDateString(),
            'daysLeft' => (int) now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false),
            'nextDue' => $earliest?->toDateString(),
        ];
    }

    // ── 4. KPI cards ───────────────────────────────────────────────────────────

    public function kpis(int $cid, array $f): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->range($f, $cid);
        $loc = $this->loc($f);
        $mode = $this->bucketMode($from, $to);
        $keys = $this->bucketKeys($from, $to, $mode);

        $agg = function (Carbon $a, Carbon $b) use ($cid, $loc) {
            $r = $this->inRange($this->sells($cid, $loc), $a, $b)
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(amount), 0) as revenue, COALESCE(SUM(gross_profit), 0) as gp, COALESCE(SUM(total_cost), 0) as cost')
                ->first();

            return [
                'orders' => (int) $r->orders,
                'revenue' => (float) $r->revenue,
                'grossProfit' => (float) $r->gp,
                'cost' => (float) $r->cost,
            ];
        };
        $cur = $agg($from, $to);
        $prev = $agg($prevFrom, $prevTo);

        $expr = $this->bucketExpr($mode, $from);
        $rows = $this->inRange($this->sells($cid, $loc), $from, $to)
            ->groupByRaw($expr)
            ->selectRaw("{$expr} as bucket, COUNT(*) as orders, SUM(amount) as revenue, SUM(gross_profit) as gp")
            ->get()
            ->keyBy('bucket');
        $series = fn(string $col) => array_map(fn($k) => (float) ($rows[$k]->{$col} ?? 0), $keys);

        $delivered = $this->inRange($this->sells($cid, $loc), $from, $to)->where('status', 'Delivered')->count();
        $pendingInRange = $this->inRange($this->sells($cid, $loc), $from, $to)->where('status', 'Pending')->count();

        $inv = $this->inventoryTotals($cid, $loc);

        $recv = $this->sells($cid)->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due, COUNT(*) as cnt, COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), order_time) >= 30 THEN 1 ELSE 0 END), 0) as overdue, COUNT(DISTINCT customer_id) as debtors, SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END) as anon')
            ->first();

        $vendorDue = DB::table('purchase_orders')->where('company_id', $cid)->whereNotIn('status', ['cancelled', 'draft'])->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due, COUNT(DISTINCT vendor_id) as vendors')
            ->first();
        $salary = $this->salaryStatus($cid);
        $poNextDue = DB::table('purchase_orders')->where('company_id', $cid)->whereNotIn('status', ['cancelled', 'draft'])
            ->where('due_amount', '>', 0)->whereNotNull('expected_date')->min('expected_date');
        $nextDue = collect([$poNextDue ? Carbon::parse($poNextDue)->toDateString() : null, $salary['nextDue']])->filter()->min();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'bucket' => $mode],
            'revenue' => [
                'value' => $cur['revenue'],
                'deltaPct' => $this->pctDelta($cur['revenue'], $prev['revenue']),
                'orders' => $cur['orders'],
                'series' => $series('revenue'),
            ],
            'grossProfit' => [
                'value' => $cur['grossProfit'],
                'deltaPct' => $this->pctDelta($cur['grossProfit'], $prev['grossProfit']),
                'marginPct' => $cur['revenue'] > 0 ? round($cur['grossProfit'] / $cur['revenue'] * 100, 1) : null,
                // Cost is only known for items whose product had a cost price at sale time.
                'costCoveragePct' => $cur['revenue'] > 0 ? round(min(1, $cur['cost'] / max($cur['revenue'] - $cur['grossProfit'], 0.0001)) * 100) : null,
                'series' => $series('gp'),
            ],
            'orders' => [
                'value' => $cur['orders'],
                'deltaPct' => $this->pctDelta((float) $cur['orders'], (float) $prev['orders']),
                'aov' => $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 2) : 0,
                'delivered' => $delivered,
                'pending' => $pendingInRange,
                'series' => $series('orders'),
            ],
            'inventory' => [
                'valueAtCost' => $inv['value'],
                'units' => $inv['units'],
                'skus' => $inv['skus'],
                'series' => null, // no stock history is stored
            ],
            'receivable' => [
                'value' => (float) $recv->due,
                'openInvoices' => (int) $recv->cnt,
                'overdue' => (int) $recv->overdue,
                // distinct customers owing (walk-in invoices without a customer count once each)
                'debtors' => (int) $recv->debtors + (int) $recv->anon,
            ],
            'payable' => [
                'value' => (float) $vendorDue->due + $salary['unpaid'],
                'vendors' => (float) $vendorDue->due,
                'vendorCount' => (int) $vendorDue->vendors,
                'salaries' => $salary['unpaid'],
                'nextDue' => $nextDue,
            ],
        ];
    }

    // ── 5. Revenue & gross profit ──────────────────────────────────────────────

    public function revenue(int $cid, array $f): array
    {
        [$from, $to] = $this->range($f, $cid);
        $loc = $this->loc($f);
        $mode = $this->bucketMode($from, $to);
        $keys = $this->bucketKeys($from, $to, $mode);
        $expr = $this->bucketExpr($mode, $from);

        $rows = $this->inRange($this->sells($cid, $loc), $from, $to)
            ->groupByRaw($expr)
            ->selectRaw("{$expr} as bucket, COALESCE(SUM(amount), 0) as revenue, COALESCE(SUM(gross_profit), 0) as gp")
            ->get()->keyBy('bucket');

        $buckets = array_map(fn($k) => [
            'key' => $k,
            'revenue' => (float) ($rows[$k]->revenue ?? 0),
            'grossProfit' => (float) ($rows[$k]->gp ?? 0),
        ], $keys);

        $tot = $this->inRange($this->sells($cid, $loc), $from, $to)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(amount), 0) as revenue, COALESCE(SUM(gross_profit), 0) as gp, COALESCE(SUM(discount), 0) as discount')
            ->first();

        $returns = (float) DB::table('customer_returns')
            ->where('company_id', $cid)
            ->whereBetween('request_date', [$from, $to])
            ->whereNotIn(DB::raw('LOWER(status)'), ['rejected', 'cancelled'])
            ->sum('total_amount');

        $best = collect($buckets)->sortByDesc('revenue')->first();

        return [
            'bucket' => $mode,
            'buckets' => $buckets,
            'best' => ($best && $best['revenue'] > 0) ? ['key' => $best['key'], 'revenue' => $best['revenue']] : null,
            'footer' => [
                'aov' => (int) $tot->orders > 0 ? round((float) $tot->revenue / (int) $tot->orders, 2) : 0,
                'grossMarginPct' => (float) $tot->revenue > 0 ? round((float) $tot->gp / (float) $tot->revenue * 100, 1) : null,
                'returnsRatePct' => (float) $tot->revenue > 0 ? round($returns / (float) $tot->revenue * 100, 1) : null,
                'discounts' => (float) $tot->discount,
            ],
        ];
    }

    // ── 6. Fulfillment pipeline ────────────────────────────────────────────────

    public function pipeline(int $cid, array $f): array
    {
        $loc = $this->loc($f);

        // Stage rules (a sale sits in exactly one stage):
        //   Counts are by sale status (not the date range); fulfillment only refines Pending/Processing.
        //   Pending   status Pending
        //   Confirmed status Processing, not yet packed or shipped
        //   Packed    fulfillment "packed"
        //   Shipped   fulfillment "shipped"
        //   Delivered status Delivered
        $r = $this->sells($cid, $loc)
            ->where(fn($q) => $q->whereNull('fulfillment_status')->orWhere('fulfillment_status', '!=', 'cancelled'))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'Pending' AND COALESCE(fulfillment_status, '') NOT IN ('packed', 'shipped') THEN 1 ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN status = 'Processing' AND COALESCE(fulfillment_status, '') NOT IN ('packed', 'shipped') THEN 1 ELSE 0 END), 0) as confirmed,
                COALESCE(SUM(CASE WHEN status = 'Packed' OR (status NOT IN ('Delivered', 'Shipped') AND fulfillment_status = 'packed') THEN 1 ELSE 0 END), 0) as packed,
                COALESCE(SUM(CASE WHEN status = 'Shipped' OR (status NOT IN ('Delivered', 'Packed') AND fulfillment_status = 'shipped') THEN 1 ELSE 0 END), 0) as shipped,
                COALESCE(SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END), 0) as delivered
            ")->first();

        $stages = [
            ['key' => 'pending', 'count' => (int) $r->pending],
            ['key' => 'confirmed', 'count' => (int) $r->confirmed],
            ['key' => 'packed', 'count' => (int) $r->packed],
            ['key' => 'shipped', 'count' => (int) $r->shipped],
            ['key' => 'delivered', 'count' => (int) $r->delivered],
        ];

        $oldest = $this->sells($cid, $loc)->where('status', 'Pending')->min('order_time');

        return [
            'stages' => $stages,
            'open' => $stages[0]['count'] + $stages[1]['count'] + $stages[2]['count'] + $stages[3]['count'],
            'oldestPendingTime' => $oldest ? Carbon::parse($oldest)->toIso8601String() : null,
        ];
    }

    // ── 7. Stock health ────────────────────────────────────────────────────────

    public function stockHealth(int $cid, array $f): array
    {
        $loc = $this->loc($f);
        $sold30 = $this->unitsSold($cid, 30, $loc);

        $products = $this->products($cid, $loc)->get(['products.id', 'products.stock', 'products.reorder_point']);

        $out = $low = $over = $healthy = 0;
        foreach ($products as $p) {
            $stock = (int) $p->stock;
            $avg = ($sold30[$p->id] ?? 0) / 30;
            if ($stock <= 0) {
                $out++;
            } elseif ((int) $p->reorder_point > 0 && $stock <= (int) $p->reorder_point) {
                $low++;
            } elseif ((int) $p->reorder_point > 0 && $stock > (int) $p->reorder_point * 3) {
                $over++;
            } else {
                $healthy++;
            }
        }

        $inv = $this->inventoryTotals($cid, $loc);
        $cogsYear = (float) DB::table('order_items')
            ->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled')
            ->when($loc, fn($q) => $q->where(fn($w) => $w->whereNull('sells.location_id')->orWhere('sells.location_id', $loc)))
            ->where('sells.order_time', '>=', now()->subDays(365))
            ->sum('order_items.total_cost');

        $turnover = $inv['value'] > 0 && $cogsYear > 0 ? round($cogsYear / $inv['value'], 1) : null;
        $unitsSold30 = array_sum($sold30);
        $dead = $this->deadStock($cid, $loc);

        return [
            'skus' => $inv['skus'],
            'units' => $inv['units'],
            'segments' => ['healthy' => $healthy, 'low' => $low, 'out' => $out, 'overstock' => $over],
            'overstockRule' => 'More than 3× the reorder point',
            'turnover' => $turnover,
            'daysOfInventory' => $turnover ? (int) round(365 / $turnover) : null,
            'sellThroughPct' => ($unitsSold30 + $inv['units']) > 0 ? round($unitsSold30 / ($unitsSold30 + $inv['units']) * 100) : null,
            'deadStock' => $dead,
        ];
    }

    // ── 8. Smart reorder suggestions ───────────────────────────────────────────

    public function reorder(int $cid, array $f): array
    {
        $loc = $this->loc($f);
        $limit = min((int) ($f['limit'] ?? 8), 25);
        $sold30 = $this->unitsSold($cid, 30, $loc);

        // Supplier = vendor of the latest purchase order containing the product (else the product's own vendor)
        $poVendor = [];
        DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.company_id', $cid)
            ->orderByDesc('purchase_orders.created_at')->orderByDesc('purchase_orders.id')
            ->get(['purchase_order_items.product_id', 'purchase_orders.vendor_id'])
            ->each(function ($r) use (&$poVendor) {
                $poVendor[$r->product_id] ??= $r->vendor_id;
            });
        $vendors = DB::table('vendors')->where('company_id', $cid)
            ->get(['id', 'name', 'lead_time_days', 'safety_days'])->keyBy('id');

        $rows = $this->products($cid, $loc)
            ->whereColumn('products.stock', '<=', 'products.reorder_point')
            ->get(['products.id', 'products.name', 'products.sku', 'products.stock', 'products.reorder_point', 'products.vendor_id']);

        $items = [];
        foreach ($rows as $p) {
            $stock = (int) $p->stock;
            $reorderPoint = (int) $p->reorder_point;
            $v = ($sold30[$p->id] ?? 0) / 30;
            $cover = $v > 0 ? $stock / $v : null;

            $vid = $poVendor[$p->id] ?? $p->vendor_id;
            $vendor = $vid ? $vendors->get($vid) : null;
            $leadKnown = $vendor && (int) $vendor->lead_time_days > 0;
            $lead = $leadKnown ? (int) $vendor->lead_time_days : self::DEFAULT_LEAD_TIME_DAYS;
            $safety = $vendor && $vendor->safety_days !== null ? (int) $vendor->safety_days : self::DEFAULT_SAFETY_DAYS;

            $suggested = max(
                $v > 0 ? (int) ceil($v * ($lead + $safety)) - $stock : 0,
                $reorderPoint * ($v > 0 ? 1 : 2) - $stock,
                1
            );

            $items[] = [
                'productId' => (int) $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'onHand' => $stock,
                'avgDailySales' => round($v, 2),
                'daysOfCover' => $cover !== null ? (int) round($cover) : null,
                'suggestedQty' => $suggested,
                'basis' => $v > 0 ? 'velocity' : 'reorder_point',
                'vendorId' => $vendor ? (int) $vendor->id : null,
                'vendorName' => $vendor?->name,
                'leadTimeDays' => $lead,
                'leadTimeIsDefault' => !$leadKnown,
                'safetyDays' => $safety,
                '_sort' => $stock <= 0 ? -1.0 : ($cover ?? 1e9),
            ];
        }

        usort($items, fn($a, $b) => $a['_sort'] <=> $b['_sort']);
        $items = array_map(function ($i) {
            unset($i['_sort']);

            return $i;
        }, $items);

        return [
            'total' => count($items),
            'items' => array_slice($items, 0, $limit),
            'defaults' => ['leadTimeDays' => self::DEFAULT_LEAD_TIME_DAYS, 'safetyDays' => self::DEFAULT_SAFETY_DAYS],
        ];
    }

    // ── 9. Top sellers ─────────────────────────────────────────────────────────

    public function topProducts(int $cid, array $f): array
    {
        [$from, $to] = $this->range($f, $cid);
        $loc = $this->loc($f);

        $rows = DB::table('order_items')
            ->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $cid)->whereNull('sells.deleted_at')->where('sells.status', '!=', 'Cancelled')
            ->whereBetween('sells.order_time', [$from->copy()->utc(), $to->copy()->utc()])
            ->when($loc, fn($q) => $q->where(fn($w) => $w->whereNull('sells.location_id')->orWhere('sells.location_id', $loc)))
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc(DB::raw('SUM(order_items.total_price)'))
            ->limit(5)
            ->selectRaw('order_items.product_id, order_items.product_name as name, SUM(order_items.total_price) as revenue, SUM(order_items.quantity) as units')
            ->get();

        return [
            'items' => $rows->map(fn($r) => [
                'productId' => $r->product_id ? (int) $r->product_id : null,
                'name' => $r->name,
                'revenue' => (float) $r->revenue,
                'units' => (int) $r->units,
            ])->all(),
            'slowMovers' => $this->deadStock($cid, $loc),
        ];
    }

    // ── 10. Sales by channel & payment method ──────────────────────────────────

    public function channels(int $cid, array $f): array
    {
        [$from, $to] = $this->range($f, $cid);
        $loc = $this->loc($f);

        // Cash on delivery is its own channel (by payment method), like the reference design
        $channelExpr = "CASE WHEN LOWER(method) IN ('cod', 'cash on delivery') THEN 'cod' ELSE COALESCE(NULLIF(source, ''), 'pos') END";
        $ch = $this->inRange($this->sells($cid, $loc), $from, $to)
            ->groupByRaw($channelExpr)
            ->selectRaw("{$channelExpr} as channel, COUNT(*) as orders, COALESCE(SUM(amount), 0) as revenue")
            ->orderByDesc('revenue')
            ->get();
        $methodExpr = "CASE WHEN LOWER(method) IN ('cod', 'cash on delivery') THEN 'cash on delivery' ELSE LOWER(COALESCE(NULLIF(method, ''), 'cash')) END";
        $pm = $this->inRange($this->sells($cid, $loc), $from, $to)
            ->groupByRaw($methodExpr)
            ->selectRaw("{$methodExpr} as method, COUNT(*) as orders, COALESCE(SUM(amount), 0) as revenue")
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();

        return [
            'channels' => $ch->map(fn($r) => ['key' => $r->channel, 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue])->all(),
            'paymentMethods' => $pm->map(fn($r) => ['method' => ucwords(str_replace('_', ' ', (string) $r->method)), 'orders' => (int) $r->orders, 'revenue' => (float) $r->revenue])->all(),
        ];
    }

    // ── 11. Cash & obligations ─────────────────────────────────────────────────

    public function obligations(int $cid, array $f): array
    {
        [$from, $to] = $this->range($f, $cid);
        $loc = $this->loc($f);
        $salary = $this->salaryStatus($cid);
        $monthEnd = now()->endOfMonth();

        $livePo = DB::table('purchase_orders')->where('company_id', $cid)->whereNotIn('status', ['cancelled', 'draft']);
        $poTotals = (clone $livePo)->selectRaw('COALESCE(SUM(total_amount), 0) as total, COALESCE(SUM(LEAST(paid_amount, total_amount)), 0) as paid')->first();
        $vendors = (clone $livePo)->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due, COUNT(DISTINCT vendor_id) as vendors, COUNT(*) as orders')
            ->first();
        $vendorsDueThisMonth = (float) (clone $livePo)->where('due_amount', '>', 0)
            ->whereRaw('COALESCE(expected_date, DATE(created_at)) <= ?', [$monthEnd->toDateString()])
            ->sum('due_amount');

        // Receivables are company-wide (a customer's debt is not tied to one branch)
        $recv = $this->sells($cid)->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount), 0) as due, COUNT(*) as cnt')->first();
        $salesTotal = (float) $this->sells($cid)->sum('amount');

        $monthQ = DB::table('expenses')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereYear('expense_date', now()->year)->whereMonth('expense_date', now()->month);
        $expenses = (float) (clone $monthQ)->sum('amount');
        $expenseCount = (clone $monthQ)->count();

        // Cash actually received on sales in the selected period
        $cashIn = (float) $this->inRange($this->sells($cid, $loc), $from, $to)->sum('paid_amount');

        return [
            'month' => $salary['month'],
            'cashIn' => $cashIn,
            'dueThisMonth' => $salary['unpaid'] + $vendorsDueThisMonth,
            'salaries' => $salary,
            'vendors' => [
                'due' => (float) $vendors->due,
                'vendorCount' => (int) $vendors->vendors,
                'orders' => (int) $vendors->orders,
                'totalOrdered' => (float) $poTotals->total,
                'totalPaid' => (float) $poTotals->paid,
            ],
            'receivables' => ['due' => (float) $recv->due, 'openInvoices' => (int) $recv->cnt, 'salesTotal' => $salesTotal],
            'expenses' => ['amount' => $expenses, 'count' => $expenseCount],
        ];
    }

    // ── 12. Recent orders ──────────────────────────────────────────────────────

    public function recentOrders(int $cid, array $f): array
    {
        $limit = min((int) ($f['limit'] ?? 6), 20);
        $rows = $this->sells($cid, $this->loc($f))->orderByDesc('order_time')->limit($limit)
            ->get(['id', 'invoice_no', 'customer_name', 'source', 'method', 'payment_status', 'amount', 'status', 'order_time']);

        return [
            'items' => $rows->map(fn($r) => [
                'id' => (int) $r->id,
                'invoiceNo' => $r->invoice_no,
                'customerName' => $r->customer_name,
                'channel' => $r->source ?: 'pos',
                'method' => $r->method,
                'paymentStatus' => $r->payment_status,
                'amount' => (float) $r->amount,
                'status' => $r->status,
                'orderTime' => Carbon::parse($r->order_time)->toIso8601String(),
            ])->all(),
        ];
    }

    // ── 13. Activity feed ──────────────────────────────────────────────────────

    public function activity(int $cid, array $f): array
    {
        $limit = min((int) ($f['limit'] ?? 8), 30);
        $loc = $this->loc($f);
        $events = [];

        $this->sells($cid, $loc)->orderByDesc('order_time')->limit($limit)
            ->get(['id', 'invoice_no', 'customer_name', 'source', 'amount', 'order_time'])
            ->each(function ($r) use (&$events) {
                $channel = ['pos' => 'In-store POS', 'storefront' => 'Online store', 'manual' => 'Manual'][$r->source ?: 'pos'] ?? ucfirst((string) $r->source);
                $events[] = [
                    'type' => 'sale',
                    'text' => 'New sale ' . number_format((float) $r->amount, 2) . ' — ' . ($r->customer_name ?: 'Walk-in') . ' (' . $channel . ')',
                    'at' => $r->order_time,
                ];
            });

        DB::table('purchase_orders')->where('company_id', $cid)->orderByDesc('created_at')->limit($limit)
            ->get(['po_number', 'status', 'total_amount', 'created_at'])
            ->each(function ($r) use (&$events) {
                $events[] = [
                    'type' => 'purchase',
                    'text' => 'Purchase order ' . $r->po_number . ' ' . $r->status . ' · ' . number_format((float) $r->total_amount, 2),
                    'at' => $r->created_at,
                ];
            });

        DB::table('expenses')->where('company_id', $cid)->whereNull('deleted_at')->orderByDesc('created_at')->limit($limit)
            ->get(['title', 'amount', 'created_at'])
            ->each(function ($r) use (&$events) {
                $events[] = [
                    'type' => 'expense',
                    'text' => 'Expense ' . number_format((float) $r->amount, 2) . ($r->title ? ' — ' . $r->title : ''),
                    'at' => $r->created_at,
                ];
            });

        DB::table('notifications')->where('company_id', $cid)->orderByDesc('created_at')->limit($limit)
            ->get(['type', 'title', 'message', 'created_at'])
            ->each(function ($r) use (&$events) {
                $events[] = [
                    'type' => $r->type,
                    'text' => trim(($r->title ?: 'Notification') . ($r->message ? ' — ' . $r->message : '')),
                    'at' => $r->created_at,
                ];
            });

        usort($events, fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return [
            'items' => array_map(fn($e) => [
                'type' => $e['type'],
                'text' => $e['text'],
                'createdAt' => Carbon::parse($e['at'])->toIso8601String(),
            ], array_slice($events, 0, $limit)),
        ];
    }
}
