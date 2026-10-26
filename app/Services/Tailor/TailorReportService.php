<?php

namespace App\Services\Tailor;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only tailor reports (orders & income, fabrics, dorjis) in the same JSON shape as the generic
 * ReportService: {type, period, subtitle, kpis[], chart, mixes[], tables[]} with typed cells.
 * Extra (backwards compatible) fields: chart.kind and mixes[].kind may be 'num' (with `unit`), cells may carry `tone`.
 */
class TailorReportService
{
    use TailorSchema;

    public const TYPES = ['orders', 'fabrics', 'dorjis'];
    private const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    private const ROW_CAP = 500;

    public function report(int $cid, string $type, array $f): array
    {
        $from = !empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : null;
        $to = !empty($f['to']) ? Carbon::parse($f['to'])->startOfDay() : null;
        $win = [$from, $to];

        $out = match ($type) {
            'fabrics' => $this->fabrics($cid, $win),
            'dorjis' => $this->dorjis($cid, $win),
            default => $this->orders($cid, $win),
        };

        return ['type' => in_array($type, self::TYPES, true) ? $type : 'orders',
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()]] + $out;
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function kpi($label, $value, string $kind, string $sub, string $tone, string $subTone = 'muted'): array
    {
        return ['label' => $label, 'value' => $kind === 'text' ? (string) $value : round((float) $value, 2), 'kind' => $kind,
            'sub' => $sub, 'tone' => $tone, 'subTone' => $subTone];
    }

    private function t($v, bool $bold = false, ?string $tone = null): array
    {
        return ['t' => 'text', 'v' => (string) $v, 'bold' => $bold] + ($tone ? ['tone' => $tone] : []);
    }

    private function m($v, bool $bold = false): array
    {
        return ['t' => 'money', 'v' => round((float) $v, 2), 'bold' => $bold];
    }

    private function i($v, bool $bold = false): array
    {
        return ['t' => 'int', 'v' => (int) round((float) $v), 'bold' => $bold];
    }

    private function table(string $title, string $empty, array $columns, array $rows): array
    {
        return ['title' => $title, 'empty' => $empty, 'columns' => $columns, 'rows' => $rows, 'totalRow' => null];
    }

    private function plural(int $n, string $w): string
    {
        return $n . ' ' . $w . ($n === 1 ? '' : 's');
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private function mix(string $title, string $kind, array $entries, ?string $unit = null): array
    {
        arsort($entries);
        $items = [];
        foreach (array_slice($entries, 0, 6, true) as $label => $v) {
            $items[] = ['label' => (string) $label, 'value' => $kind === 'int' ? (int) $v : round((float) $v, 2)];
        }

        return ['title' => $title, 'kind' => $kind, 'unit' => $unit, 'items' => $items];
    }

    private function applyWindow(Builder $q, string $col, array $win, bool $dateColumn): Builder
    {
        [$from, $to] = $win;
        if ($from) {
            $q->where($col, '>=', $dateColumn ? $from->toDateString() : $from->toDateTimeString());
        }
        if ($to) {
            $dateColumn ? $q->where($col, '<=', $to->toDateString())
                : $q->where($col, '<', $to->copy()->addDay()->toDateTimeString());
        }

        return $q;
    }

    private function orderDay(): string
    {
        return 'COALESCE(tailor_orders.order_date, DATE(tailor_orders.created_at))';
    }

    private function orderQ(int $cid, array $win, bool $includeCancelled): Builder
    {
        $q = DB::table('tailor_orders')->where('tailor_orders.company_id', $cid)->whereNull('tailor_orders.deleted_at');
        if (!$includeCancelled) {
            $q->where('tailor_orders.order_status', '!=', 'cancelled');
        }
        [$from, $to] = $win;
        $day = $this->orderDay();
        if ($from) $q->whereRaw("$day >= ?", [$from->toDateString()]);
        if ($to) $q->whereRaw("$day <= ?", [$to->toDateString()]);

        return $q;
    }

    private function doneDay(): string
    {
        $c = $this->pickCol('tailor_assignments', ['completed_at', 'done_at', 'completed_date']);

        return $c ? "DATE(COALESCE(tailor_assignments.$c, tailor_assignments.updated_at))" : 'DATE(tailor_assignments.updated_at)';
    }

    private function doneQ(int $cid, array $win): Builder
    {
        $q = DB::table('tailor_assignments')->where('tailor_assignments.company_id', $cid)->whereNull('tailor_assignments.deleted_at')
            ->whereIn('tailor_assignments.work_status', self::DONE_WORK);
        [$from, $to] = $win;
        $day = $this->doneDay();
        if ($from) $q->whereRaw("$day >= ?", [$from->toDateString()]);
        if ($to) $q->whereRaw("$day <= ?", [$to->toDateString()]);

        return $q;
    }

    /** Restrict an order query to orders whose fabric was actually consumed (cut). */
    private function fabricConsumed(Builder $q): Builder
    {
        if ($this->hasCol('tailor_orders', 'fabric_deducted')) {
            return $q->where('tailor_orders.fabric_deducted', true);
        }

        return $q->whereNotIn('tailor_orders.order_status', ['new', 'pending', 'measurement_taken', 'received', 'cancelled']);
    }

    /** Daily buckets when span <= 31 days, otherwise the last 12 months. */
    private function buckets(int $cid, array $win, string $type): array
    {
        [$from, $to] = $win;
        if (!$from) {
            $min = DB::table('tailor_orders')->where('company_id', $cid)->whereNull('deleted_at')->min(DB::raw($this->orderDay()));
            if ($type === 'dorjis') {
                $m2 = DB::table('tailor_assignments')->where('tailor_assignments.company_id', $cid)->whereNull('tailor_assignments.deleted_at')
                    ->whereIn('tailor_assignments.work_status', self::DONE_WORK)->min(DB::raw($this->doneDay()));
                $min = array_filter([$min, $m2]) ? min(array_filter([$min, $m2])) : null;
            }
            $from = $min ? Carbon::parse($min)->startOfDay() : now()->startOfDay();
        }
        $to = $to ?: now()->startOfDay();
        $span = max(1, (int) round($from->diffInDays($to, false)) + 1);
        $daily = $span <= 31;
        $buckets = [];
        if ($daily) {
            for ($n = 0; $n < $span; $n++) {
                $d = $from->copy()->addDays($n);
                $buckets[] = ['key' => $d->toDateString(), 'label' => $span <= 7 ? self::MON[$d->month - 1] . ' ' . $d->day : (string) $d->day];
            }
        } else {
            $y = $from->year; $mo = $from->month; $ey = $to->year; $em = $to->month;
            while (($y < $ey || ($y === $ey && $mo <= $em)) && count($buckets) < 240) {
                $buckets[] = ['key' => sprintf('%04d-%02d', $y, $mo), 'label' => self::MON[$mo - 1] . ($y !== $ey ? " '" . substr((string) $y, 2) : '')];
                if (++$mo > 12) { $mo = 1; $y++; }
            }
            $buckets = array_slice($buckets, -12);
        }

        return ['daily' => $daily, 'buckets' => $buckets];
    }

    private function chart(string $title, string $kind, ?string $unit, array $buckets, array $byKey): array
    {
        $bars = [];
        $has = false;
        foreach ($buckets as $b) {
            $v = round((float) ($byKey[$b['key']] ?? 0), 2);
            $has = $has || $v != 0;
            $bars[] = ['label' => $b['label'], 'value' => $v];
        }

        return ['title' => $title, 'signed' => false, 'kind' => $kind, 'unit' => $unit, 'bars' => $has ? $bars : []];
    }

    private function bucketKey(bool $daily, string $col): string
    {
        return $daily ? "DATE($col)" : "DATE_FORMAT($col, '%Y-%m')";
    }

    private function payLabel(string $s): string
    {
        return ['paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid'][$s];
    }

    // ── Orders & income ────────────────────────────────────────────────────────

    private function orders(int $cid, array $win): array
    {
        $a = $this->orderQ($cid, $win, false)->selectRaw(
            "COUNT(*) as n, COALESCE(SUM(total_amount),0) as rev, COALESCE(SUM(paid_amount),0) as col,
             COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)),0) as due,
             SUM(GREATEST(total_amount - paid_amount, 0) > 0) as due_n, SUM(order_status = 'delivered') as deliv"
        )->first();
        $n = (int) $a->n; $rev = (float) $a->rev; $col = (float) $a->col; $due = (float) $a->due; $dueN = (int) $a->due_n; $deliv = (int) $a->deliv;

        $kpis = [
            $this->kpi('Orders', $n, 'int', 'Placed in this period', 'blue'),
            $this->kpi('Revenue', $rev, 'money', 'Total value of orders', 'teal'),
            $this->kpi('Collected', $col, 'money', $rev ? round($col / $rev * 100) . '% of revenue' : 'No revenue yet', 'teal'),
            $this->kpi('Still due', $due, 'money', $this->plural($dueN, 'order'), 'red', $due ? 'red' : 'muted'),
            $this->kpi('Delivered', $deliv, 'int', $n ? round($deliv / $n * 100) . '% of orders' : 'No orders yet', 'purple'),
        ];

        $bk = $this->buckets($cid, $win, 'orders');
        $day = $this->orderDay();
        $expr = $bk['daily'] ? $day : "DATE_FORMAT($day, '%Y-%m')";
        $byKey = $this->orderQ($cid, $win, false)->groupByRaw($expr)->selectRaw("$expr as k, SUM(total_amount) as v")->pluck('v', 'k')->all();
        $chart = $this->chart($bk['daily'] ? 'Revenue per day' : 'Revenue per month', 'money', null, $bk['buckets'], $byKey);

        $g = $this->orderQ($cid, $win, false)->join('tailor_order_items', 'tailor_order_items.order_id', '=', 'tailor_orders.id')
            ->groupByRaw($this->garmentExpr())->selectRaw($this->garmentExpr() . ' as k, SUM(' . $this->itemQtyExpr() . ') as v')->pluck('v', 'k')->all();
        $garments = [];
        foreach ($g as $k => $v) {
            $garments[$k ?: 'Other'] = ($garments[$k ?: 'Other'] ?? 0) + (float) $v;
        }
        $bs = $this->orderQ($cid, $win, true)->selectRaw('order_status as k, COUNT(*) as v')->groupBy('order_status')->pluck('v', 'k')->all();
        $byStage = [];
        foreach ($bs as $k => $v) {
            $byStage[self::STAGES[$this->stage($k)]] = ($byStage[self::STAGES[$this->stage($k)]] ?? 0) + (int) $v;
        }
        $mixes = [$this->mix('Garments ordered', 'int', $garments), $this->mix('Orders by status', 'int', $byStage)];

        $rows = $this->orderQ($cid, $win, true)->leftJoin('tailor_customers', 'tailor_customers.id', '=', 'tailor_orders.customer_id')
            ->orderByRaw("$day DESC")->orderByDesc('tailor_orders.id')->limit(self::ROW_CAP)
            ->selectRaw("tailor_orders.order_number, $day as d, tailor_customers.name as customer, tailor_orders.delivery_date,
                tailor_orders.total_amount, tailor_orders.paid_amount, tailor_orders.order_status")->get();
        $trows = [];
        foreach ($rows as $o) {
            $st = $this->stage($o->order_status);
            $cx = $st === 'cancelled';
            $tot = (float) $o->total_amount; $paid = (float) $o->paid_amount;
            $pay = $cx ? null : $this->payState($tot, $paid);
            $trows[] = [
                $this->t($o->order_number, true), $this->t(Carbon::parse($o->d)->format('M j, Y')), $this->t($o->customer ?: 'Walk-in'),
                $this->t($o->delivery_date ? Carbon::parse($o->delivery_date)->format('M j, Y') : '—'),
                $this->m($tot), $this->m($paid), $this->m($cx ? 0 : max(0, $tot - $paid)),
                $this->t($pay ? $this->payLabel($pay) : '—', false, $pay), $this->t(self::STAGES[$st], false, 'stage:' . $st),
            ];
        }

        return ['subtitle' => 'How the workshop is doing — orders, fabric and dorjis.', 'kpis' => $kpis, 'chart' => $chart, 'mixes' => $mixes,
            'tables' => [$this->table('Orders', 'No tailor orders in this period.',
                ['Order', 'Date', 'Customer', 'Delivery', 'Total', 'Paid', 'Due', 'Payment', 'Status'], $trows)]];
    }

    // ── Fabrics ────────────────────────────────────────────────────────────────

    private function fabrics(int $cid, array $win): array
    {
        $fabrics = DB::table('tailor_fabrics')->where('company_id', $cid)->whereNull('deleted_at')->get();
        $units = $fabrics->pluck('unit')->filter()->unique()->values();
        $unit = $units->count() === 1 ? (string) $units[0] : null;
        $sfx = $unit ? ' ' . $unit : '';

        $used = $this->fabricConsumed($this->orderQ($cid, $win, false))->join('tailor_order_items', 'tailor_order_items.order_id', '=', 'tailor_orders.id')
            ->whereNotNull('tailor_order_items.fabric_id')->where('tailor_order_items.fabric_quantity', '>', 0)
            ->groupBy('tailor_order_items.fabric_id')->selectRaw('tailor_order_items.fabric_id as k, SUM(tailor_order_items.fabric_quantity) as v')
            ->pluck('v', 'k')->all();
        $byId = $fabrics->keyBy('id');
        $sell = fn($f) => $f ? ((float) $f->selling_price ?: (float) $f->purchase_price) : 0.0;
        $totalUsed = array_sum(array_map('floatval', $used));
        $usedValue = 0.0;
        foreach ($used as $id => $m) {
            $usedValue += (float) $m * $sell($byId[$id] ?? null);
        }
        $reorderCol = $this->pickCol('tailor_fabrics', ['reorder_level', 'reorder_meters', 'reorder_quantity', 'reorder_point', 'low_stock_level']);
        $reorderOf = fn($f) => $reorderCol ? (float) $f->$reorderCol : 5.0;
        $activeF = $fabrics->filter(fn($f) => strtolower((string) $f->status) !== 'inactive');
        $stockValue = $activeF->sum(fn($f) => (float) $f->stock_quantity * (float) $f->purchase_price);
        $low = $activeF->filter(fn($f) => (float) $f->stock_quantity <= $reorderOf($f))->values();

        $kpis = [
            $this->kpi('Fabric used', $this->num($totalUsed) . $sfx, 'text', 'In this period', 'blue'),
            $this->kpi('Value used', $usedValue, 'money', 'At sell price per unit', 'teal'),
            $this->kpi('Stock value', $stockValue, 'money', $this->plural($fabrics->count(), 'fabric'), 'purple'),
            $this->kpi('Low / out', $low->count(), 'int', $low->count() ? $low->take(2)->pluck('name')->implode(', ') : 'Stock is healthy', 'amber', $low->count() ? 'amber' : 'muted'),
        ];

        $bk = $this->buckets($cid, $win, 'fabrics');
        $day = $this->orderDay();
        $expr = $bk['daily'] ? $day : "DATE_FORMAT($day, '%Y-%m')";
        $byKey = $this->fabricConsumed($this->orderQ($cid, $win, false))->join('tailor_order_items', 'tailor_order_items.order_id', '=', 'tailor_orders.id')
            ->whereNotNull('tailor_order_items.fabric_id')->groupByRaw($expr)
            ->selectRaw("$expr as k, SUM(tailor_order_items.fabric_quantity) as v")->pluck('v', 'k')->all();
        $chart = $this->chart($bk['daily'] ? 'Meters used per day' : 'Meters used per month', 'num', $unit, $bk['buckets'], $byKey);

        $top = [];
        foreach ($used as $id => $m) {
            $nm = ($byId[$id]->name ?? null) ?: 'Unknown fabric';
            $top[$nm] = ($top[$nm] ?? 0) + (float) $m;
        }
        $mixes = [$this->mix('Top fabrics', 'num', $top, $unit)];

        $list = $fabrics->map(fn($f) => [$f, (float) ($used[$f->id] ?? 0)])->sortByDesc(fn($p) => $p[1])->values()->take(self::ROW_CAP);
        $trows = [];
        foreach ($list as [$f, $u]) {
            $stock = (float) $f->stock_quantity;
            $status = strtolower((string) $f->status) === 'inactive' ? 'Inactive' : ($stock <= 0 ? 'Out of stock' : ($stock <= $reorderOf($f) ? 'Low' : 'OK'));
            $trows[] = [
                $this->t($f->name, true), $this->t($f->fabric_type ?: '—'), $this->t($f->color ?: '—'), $this->t($f->unit ?: '—'),
                $this->t($this->num($u)), $this->m($u * $sell($f)), $this->t($this->num($stock)),
                $this->m($f->purchase_price), $f->selling_price ? $this->m($f->selling_price) : $this->t('—'),
                $this->m($stock * (float) $f->purchase_price), $this->t($status, false, 'fabric:' . strtolower(str_replace(' ', '-', $status))),
            ];
        }

        return ['subtitle' => 'How the workshop is doing — orders, fabric and dorjis.', 'kpis' => $kpis, 'chart' => $chart, 'mixes' => $mixes,
            'tables' => [$this->table('Fabrics', $fabrics->count() ? 'No fabric has been used in this period.' : 'No fabrics added yet.',
                ['Fabric', 'Type', 'Colour', 'Unit', 'Used', 'Value used', 'In stock', 'Buy / unit', 'Sell / unit', 'Stock value', 'Status'], $trows)]];
    }

    // ── Dorjis ─────────────────────────────────────────────────────────────────

    private function specText($v): string
    {
        if (is_string($v)) {
            $j = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($j)) $v = $j;
            elseif (json_last_error() === JSON_ERROR_NONE && is_string($j)) $v = $j;
        }
        if (is_array($v)) return implode(', ', array_filter(array_map(fn($x) => trim((string) $x), $v), 'strlen'));
        return trim((string) $v);
    }

    private function dorjis(int $cid, array $win): array
    {
        $qty = $this->assignQtyExpr();
        $rateCol = $this->pickCol('tailor_assignments', ['rate_per_piece']);
        $earnExpr = $rateCol ? "COALESCE(tailor_assignments.dorji_charge, $qty * tailor_assignments.$rateCol, 0)" : 'COALESCE(tailor_assignments.dorji_charge, 0)';
        $paidCol = $this->pickCol('tailor_assignments', ['paid_amount']);
        $unpaidExpr = $paidCol
            ? "CASE WHEN tailor_assignments.work_status = 'paid' THEN 0 ELSE GREATEST($earnExpr - COALESCE(tailor_assignments.$paidCol, 0), 0) END"
            : "CASE WHEN tailor_assignments.work_status = 'paid' THEN 0 ELSE $earnExpr END";

        $per = $this->doneQ($cid, $win)->groupBy('tailor_assignments.dorji_id')->selectRaw(
            "tailor_assignments.dorji_id as id, SUM($qty) as pieces, SUM($earnExpr) as earned, SUM($unpaidExpr) as unpaid, COUNT(DISTINCT tailor_assignments.order_id) as jobs"
        )->get()->keyBy('id');
        $openBy = DB::table('tailor_assignments')->where('tailor_assignments.company_id', $cid)->whereNull('tailor_assignments.deleted_at')
            ->whereIn('tailor_assignments.work_status', self::OPEN_WORK)->groupBy('dorji_id')
            ->selectRaw("dorji_id, SUM($qty) as n")->pluck('n', 'dorji_id');
        $givenQ = DB::table('tailor_assignments')->where('tailor_assignments.company_id', $cid)->whereNull('tailor_assignments.deleted_at');
        [$from, $to] = $win;
        if ($from) $givenQ->whereRaw('COALESCE(tailor_assignments.assigned_date, DATE(tailor_assignments.created_at)) >= ?', [$from->toDateString()]);
        if ($to) $givenQ->whereRaw('COALESCE(tailor_assignments.assigned_date, DATE(tailor_assignments.created_at)) <= ?', [$to->toDateString()]);
        $given = $givenQ->groupBy('dorji_id')->selectRaw('dorji_id, COUNT(DISTINCT order_id) as n')->pluck('n', 'dorji_id');

        $tPieces = (int) $per->sum('pieces'); $tEarned = (float) $per->sum('earned'); $tUnpaid = (float) $per->sum('unpaid');
        $openN = (int) $openBy->sum();
        $kpis = [
            $this->kpi('Pieces finished', $tPieces, 'int', $this->plural($per->count(), 'dorji'), 'blue'),
            $this->kpi('Commission earned', $tEarned, 'money', 'Paid ' . number_format($tEarned - $tUnpaid, 2), 'teal'),
            $this->kpi('Unpaid', $tUnpaid, 'money', $tUnpaid > 0 ? 'Pay from Assignments' : 'All settled', 'red', $tUnpaid > 0 ? 'red' : 'muted'),
            $this->kpi('Open pieces', $openN, 'int', 'Assigned, not finished', 'amber'),
        ];

        $bk = $this->buckets($cid, $win, 'dorjis');
        $dd = $this->doneDay();
        $expr = $bk['daily'] ? $dd : "DATE_FORMAT($dd, '%Y-%m')";
        $byKey = $this->doneQ($cid, $win)->groupByRaw($expr)->selectRaw("$expr as k, SUM($qty) as v")->pluck('v', 'k')->all();
        $chart = $this->chart($bk['daily'] ? 'Pieces finished per day' : 'Pieces finished per month', 'int', null, $bk['buckets'], $byKey);

        $dorjis = DB::table('tailor_dorjis')->where('company_id', $cid)->whereNull('deleted_at')->get();
        $byId = $dorjis->keyBy('id');
        $mixData = [];
        foreach ($per as $id => $p) {
            $nm = ($byId[$id]->name ?? null) ?: 'Unknown';
            $mixData[$nm] = ($mixData[$nm] ?? 0) + (int) $p->pieces;
        }
        $mixes = [$this->mix('Pieces by dorji', 'int', $mixData)];

        $commTxt = function ($d) {
            $v = (float) $d->commission_value;
            return match (strtolower((string) $d->commission_type)) {
                'percentage', 'pct' => $this->num($v) . '% of stitching',
                'salary' => number_format($v, 2) . ' / month',
                default => number_format($v, 2) . ' / piece',
            };
        };
        $ids = $dorjis->pluck('id')->all();
        foreach (array_merge($per->keys()->all(), $openBy->keys()->all()) as $x) {
            if (!in_array($x, $ids)) $ids[] = $x;
        }
        $trows = [];
        foreach (array_slice($ids, 0, self::ROW_CAP) as $id) {
            $d = $byId[$id] ?? null; $p = $per[$id] ?? null;
            $trows[] = [
                $this->t($d->name ?? 'Unknown', true), $this->t(($d->phone ?? '') ?: '—'), $this->t($this->specText($d->speciality ?? null) ?: '—'),
                $this->t($d ? $commTxt($d) : '—'), $this->i($given[$id] ?? 0), $this->i($p->jobs ?? 0), $this->i($p->pieces ?? 0),
                $this->m($p->earned ?? 0), $this->m($p->unpaid ?? 0), $this->i($openBy[$id] ?? 0),
            ];
        }

        return ['subtitle' => 'How the workshop is doing — orders, fabric and dorjis.', 'kpis' => $kpis, 'chart' => $chart, 'mixes' => $mixes,
            'tables' => [$this->table('Dorjis', 'No assignments finished in this period.',
                ['Dorji', 'Phone', 'Speciality', 'Commission', 'Orders given', 'Completed', 'Pieces', 'Earned', 'Unpaid', 'Open now'], $trows)]];
    }
}
