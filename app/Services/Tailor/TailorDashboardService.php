<?php

namespace App\Services\Tailor;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only data for the "Tailor shop" dashboard (production board, collections, dorji workload,
 * fabric alerts, recent / due-soon orders, KPIs). Company scoped, computed from the live tailor tables.
 */
class TailorDashboardService
{
    use TailorSchema;

    private const BOARD = ['new', 'cutting', 'stitching', 'trial', 'ready'];

    public function dashboard(int $cid): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth()->toDateString();

        $base = fn() => DB::table('tailor_orders')->where('tailor_orders.company_id', $cid)->whereNull('tailor_orders.deleted_at');
        $custName = fn($q) => $q->leftJoin('tailor_customers', 'tailor_customers.id', '=', 'tailor_orders.customer_id');
        $dayExpr = 'COALESCE(tailor_orders.order_date, DATE(tailor_orders.created_at))';

        // Live (non cancelled) aggregates for KPIs.
        $a = $base()->where('tailor_orders.order_status', '!=', 'cancelled')->selectRaw(
            "COUNT(*) as live_n,
             COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)),0) as outstanding,
             SUM(GREATEST(total_amount - paid_amount, 0) > 0 AND order_status != 'delivered') as due_n,
             SUM({$dayExpr} = ?) as today_n,
             COALESCE(SUM(CASE WHEN {$dayExpr} = ? THEN total_amount ELSE 0 END),0) as today_total,
             SUM(order_status = 'delivered') as delivered_n,
             SUM({$dayExpr} >= ?) as month_n,
             COALESCE(SUM(CASE WHEN {$dayExpr} >= ? THEN total_amount ELSE 0 END),0) as month_rev,
             COALESCE(SUM(CASE WHEN {$dayExpr} >= ? THEN paid_amount ELSE 0 END),0) as month_col",
            [$today->toDateString(), $today->toDateString(), $monthStart, $monthStart, $monthStart]
        )->first();
        $total = $base()->count();

        // Open orders (not delivered / cancelled) drive the board, dues and due-soon lists.
        $open = $custName($base())->whereNotIn('tailor_orders.order_status', ['delivered', 'cancelled'])
            ->orderByRaw('tailor_orders.delivery_date IS NULL, tailor_orders.delivery_date')->orderBy('tailor_orders.id')
            ->get(['tailor_orders.id', 'tailor_orders.order_number', 'tailor_orders.order_status', 'tailor_orders.delivery_date',
                'tailor_orders.total_amount', 'tailor_orders.paid_amount', 'tailor_customers.name as customer', 'tailor_customers.phone as phone']);

        $days = function ($d) use ($today): ?int {
            return $d ? (int) $today->diffInDays(Carbon::parse($d)->startOfDay(), false) : null;
        };

        // Dorji assigned per order (latest open assignment).
        $assignQ = DB::table('tailor_assignments')->join('tailor_dorjis', 'tailor_dorjis.id', '=', 'tailor_assignments.dorji_id')
            ->where('tailor_assignments.company_id', $cid)->whereNull('tailor_assignments.deleted_at')
            ->orderBy('tailor_assignments.id')->get(['tailor_assignments.order_id', 'tailor_dorjis.name']);
        $dorjiByOrder = [];
        foreach ($assignQ as $r) {
            $dorjiByOrder[$r->order_id] = $r->name;
        }
        // The order's own dorji (orders.dorji_id) wins over the latest assignment.
        if ($this->hasCol('tailor_orders', 'dorji_id')) {
            $own = DB::table('tailor_orders')->join('tailor_dorjis', 'tailor_dorjis.id', '=', 'tailor_orders.dorji_id')
                ->where('tailor_orders.company_id', $cid)->whereNotNull('tailor_orders.dorji_id')
                ->pluck('tailor_dorjis.name', 'tailor_orders.id');
            foreach ($own as $oid => $nm) {
                $dorjiByOrder[$oid] = $nm;
            }
        }

        $items = $this->itemsText($open->pluck('id')->all());

        $overdue = 0; $dueToday = 0; $dueWeek = 0; $ready = 0;
        $cols = array_fill_keys(self::BOARD, []);
        $dueSoon = [];
        $dues = [];
        foreach ($open as $o) {
            $d = $days($o->delivery_date);
            $st = $this->stage($o->order_status);
            if ($d !== null && $d < 0) $overdue++;
            if ($d === 0) $dueToday++;
            if ($d !== null && $d >= 0 && $d <= 6) $dueWeek++;
            if ($st === 'ready') $ready++;
            $row = [
                'id' => (int) $o->id, 'order_no' => $o->order_number, 'customer' => $o->customer ?: 'Walk-in',
                'items' => $items[$o->id] ?? '—', 'dorji' => $dorjiByOrder[$o->id] ?? null,
                'delivery_date' => $o->delivery_date, 'days_left' => $d,
                'urgent' => $d !== null && $d <= 1 && $st !== 'ready',
            ];
            $cols[$st][] = $row;
            if ($st !== 'ready' && $d !== null && $d <= 7 && count($dueSoon) < 10) {
                $dueSoon[] = $row;
            }
            $due = max(0, (float) $o->total_amount - (float) $o->paid_amount);
            if ($due > 0 && count($dues) < 8) {
                $dues[] = ['id' => (int) $o->id, 'order_no' => $o->order_number, 'customer' => $o->customer ?: 'Walk-in',
                    'stage' => $st, 'stage_label' => self::STAGES[$st], 'phone' => $o->phone, 'due' => round($due, 2)];
            }
        }
        $board = [];
        foreach (self::BOARD as $k) {
            $board[] = ['key' => $k, 'label' => $k === 'new' ? 'New' : self::STAGES[$k], 'count' => count($cols[$k]), 'orders' => $cols[$k]];
        }

        // Dorji workload.
        $dorjis = DB::table('tailor_dorjis')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'name', 'status']);
        $isActive = fn($d) => strtolower((string) $d->status) === 'active';
        $openBy = DB::table('tailor_assignments')->where('company_id', $cid)->whereNull('deleted_at')
            ->whereIn('work_status', self::OPEN_WORK)->groupBy('dorji_id')
            ->selectRaw('dorji_id, SUM(' . $this->assignQtyExpr() . ') as n')->pluck('n', 'dorji_id');
        $raw = [];
        foreach ($dorjis as $d) {
            if (strtolower((string) $d->status) === 'inactive') continue;
            $raw[] = ['id' => (int) $d->id, 'name' => $d->name, 'open' => (int) ($openBy[$d->id] ?? 0)];
        }
        $max = max(1, ...array_column($raw ?: [['open' => 0]], 'open'));
        $workload = array_map(fn($r) => $r + ['percent' => $r['open'] ? max(4, (int) round($r['open'] / $max * 100)) : 0], $raw);
        $advice = null;
        if (count($raw) >= 2) {
            $s = $raw;
            usort($s, fn($x, $y) => $y['open'] <=> $x['open']);
            $hi = $s[0]; $lo = end($s);
            if ($hi['open'] > $lo['open']) {
                $advice = ['head' => $hi['name'] . ' has the most work.', 'body' => 'Assign new pieces to ' . $lo['name'] . ' (' . $lo['open'] . ' open).'];
            }
        }
        $activeDorjis = $dorjis->filter($isActive)->count();

        // Fabric alerts.
        $reorder = $this->reorderExpr();
        $fabQ = fn() => DB::table('tailor_fabrics')->where('company_id', $cid)->whereNull('deleted_at')->where('status', '!=', 'inactive');
        $fabricsCount = $fabQ()->count();
        $low = $fabQ()->whereRaw("stock_quantity <= {$reorder}")->orderBy('stock_quantity')
            ->selectRaw("id, name, color, unit, stock_quantity, {$reorder} as reorder" . ($this->hasCol('tailor_fabrics', 'hex_color') ? ', hex_color' : ", NULL as hex_color"))->get()
            ->map(fn($f) => [
                'id' => (int) $f->id, 'name' => $f->name, 'color' => $f->color, 'hex_color' => $f->hex_color, 'unit' => $f->unit,
                'stock' => (float) $f->stock_quantity, 'reorder_level' => (float) $f->reorder, 'out' => (float) $f->stock_quantity <= 0,
            ])->all();

        // Recent orders.
        $recent = $custName($base())->orderByDesc('tailor_orders.created_at')->orderByDesc('tailor_orders.id')->limit(5)
            ->get(['tailor_orders.id', 'tailor_orders.order_number', 'tailor_orders.order_status', 'tailor_orders.total_amount',
                'tailor_orders.paid_amount', 'tailor_customers.name as customer'])
            ->map(function ($o) {
                $st = $this->stage($o->order_status);
                return ['id' => (int) $o->id, 'order_no' => $o->order_number, 'customer' => $o->customer ?: 'Walk-in',
                    'total' => round((float) $o->total_amount, 2), 'stage' => $st, 'stage_label' => self::STAGES[$st],
                    'payment' => $st === 'cancelled' ? null : $this->payState((float) $o->total_amount, (float) $o->paid_amount)];
            })->all();

        $deliveredMonth = DB::table('tailor_orders')->where('company_id', $cid)->whereNull('deleted_at')->where('order_status', 'delivered')
            ->where('updated_at', '>=', $monthStart)->count();

        $n = fn($v) => (int) $v;
        $kpi = fn($label, $value, $kind, $sub, $tone, $subTone = 'muted') => ['label' => $label, 'value' => $kind === 'text' ? $value : round((float) $value, 2), 'kind' => $kind, 'sub' => $sub, 'tone' => $tone, 'subTone' => $subTone];
        $plural = fn($c, $w) => $c . ' ' . $w . ($c === 1 ? '' : 's');
        $kpis = [
            $kpi('Today’s orders', $n($a->today_n), 'int', number_format((float) $a->today_total, 2) . ' booked', 'blue'),
            $kpi('Delivered', $n($a->delivered_n), 'int', $deliveredMonth . ' this month', 'teal', 'teal'),
            $kpi('Active dorjis', $activeDorjis, 'int', ($dorjis->count() - $activeDorjis) . ' inactive / on leave', 'purple'),
            $kpi('Open orders', $open->count(), 'int', $n($a->month_n) . ' placed this month', 'blue'),
            $kpi('Due this week', $dueWeek, 'int', $dueToday . ' due today', 'amber', 'amber'),
            $kpi('Overdue', $overdue, 'int', $overdue ? 'Needs attention' : 'All on schedule', 'red', $overdue ? 'red' : 'muted'),
            $kpi('Ready for pickup', $ready, 'int', $ready ? 'Remind customers' : 'Nothing waiting', 'teal', $ready ? 'teal' : 'muted'),
            $kpi('Revenue this month', (float) $a->month_rev, 'money', number_format((float) $a->month_col, 2) . ' collected', 'purple', 'teal'),
            $kpi('Customers owe', (float) $a->outstanding, 'money', $plural($n($a->due_n), 'open balance'), 'amber', 'amber'),
            $kpi('Low fabrics', count($low), 'int', $low ? implode(', ', array_map(fn($f) => $f['name'], array_slice($low, 0, 2))) : 'Stock is healthy', 'amber', $low ? 'amber' : 'muted'),
        ];

        return [
            'kpis' => $kpis,
            'board' => $board,
            'dues' => $dues,
            'workload' => $workload,
            'advice' => $advice,
            'fabric_alerts' => $low,
            'fabrics_count' => $fabricsCount,
            'recent_orders' => $recent,
            'due_soon' => $dueSoon,
            'has_orders' => $total > 0,
        ];
    }
}
