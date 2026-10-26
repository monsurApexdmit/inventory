<?php

namespace App\Services\Tailor;

use App\Models\TailorAssignment;
use App\Models\TailorDorji;
use App\Models\TailorOrder;
use App\Models\TailorOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the "Tailor orders" screen: board listing with stats / status
 * counts, order detail payload and new-order form options.
 */
class TailorOrderQueryService
{
    /** Pipeline shown as the 6-segment progress bar (index = stage_index). */
    public const STAGES = ['new', 'cutting', 'stitching', 'trial', 'ready', 'delivered'];

    /** Raw order_status values that belong to the "Received" (new) stage. */
    public const NEW_STATUSES = ['pending', 'measurement_taken', 'assigned'];

    public const CLOSED = ['delivered', 'cancelled'];

    public const URGENT_FEE = 300;

    public static function stageOf(string $status): string
    {
        return in_array($status, self::NEW_STATUSES, true) ? 'new' : $status;
    }

    /** Apply a status tab (open | new | cutting | ... | all) to a query. */
    private function applyTab(Builder $q, string $tab): Builder
    {
        return match ($tab) {
            'all', ''   => $q,
            'open'      => $q->whereNotIn('order_status', self::CLOSED),
            'new'       => $q->whereIn('order_status', self::NEW_STATUSES),
            default     => $q->where('order_status', $tab),
        };
    }

    public function board(int $companyId, array $f): array
    {
        $today = today()->toDateString();
        $tab   = (string) ($f['status'] ?? 'open');

        $q = TailorOrder::where('company_id', $companyId);
        $this->applyTab($q, $tab);

        if (!empty($f['search'])) {
            $s = trim((string) $f['search']);
            $q->where(function ($w) use ($s) {
                $w->where('order_number', 'like', "%{$s}%")
                  ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
            });
        }
        if (!empty($f['dorji_id'])) {
            if ($f['dorji_id'] === 'none') {
                $q->whereDoesntHave('assignments');
            } else {
                $q->whereHas('assignments', fn ($a) => $a->where('dorji_id', (int) $f['dorji_id']));
            }
        }
        if (!empty($f['payment_status']) && in_array($f['payment_status'], ['unpaid', 'partial', 'paid'], true)) {
            $q->where('payment_status', $f['payment_status']);
        }
        if (!empty($f['delivery_from'])) $q->whereDate('delivery_date', '>=', $f['delivery_from']);
        if (!empty($f['delivery_to']))   $q->whereDate('delivery_date', '<=', $f['delivery_to']);
        if (!empty($f['delivery'])) {
            $q->whereNotIn('order_status', self::CLOSED);
            match ($f['delivery']) {
                'overdue' => $q->whereDate('delivery_date', '<', $today),
                'today'   => $q->whereDate('delivery_date', $today),
                'week'    => $q->whereDate('delivery_date', '>=', $today)->whereDate('delivery_date', '<=', today()->addDays(7)->toDateString()),
                default   => null,
            };
        }

        $limit = max(1, min((int) ($f['limit'] ?? 10), 100));
        $page  = max(1, (int) ($f['page'] ?? 1));

        $result = $q->with(['customer', 'items', 'assignments.dorji'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return [
            'orders'        => collect($result->items())->map(fn ($o) => $this->row($o))->values()->all(),
            'pagination'    => [
                'page'      => $result->currentPage(),
                'per_page'  => $result->perPage(),
                'total'     => $result->total(),
                'last_page' => $result->lastPage(),
            ],
            'stats'         => $this->stats($companyId),
            'status_counts' => $this->statusCounts($companyId),
        ];
    }

    public function row(TailorOrder $o): array
    {
        $status    = (string) $o->order_status;
        $stage     = self::stageOf($status);
        $stageIdx  = array_search($stage, self::STAGES, true);
        $cancelled = $status === 'cancelled';
        $open      = !in_array($status, self::CLOSED, true);
        $due       = $cancelled ? 0.0 : max(0.0, round((float) $o->total_amount - (float) $o->paid_amount, 2));
        $next      = $open && $stageIdx !== false ? (self::STAGES[$stageIdx + 1] ?? null) : null;

        $assigns = $o->relationLoaded('assignments') ? $o->assignments : collect();
        $dorjis  = $assigns->map(fn ($a) => $a->dorji?->name)->filter()->unique()->values()->all();
        $items   = $o->relationLoaded('items') ? $o->items : collect();

        $days = $o->delivery_date ? (int) today()->diffInDays($o->delivery_date->copy()->startOfDay(), false) : null;

        return [
            'id'             => $o->id,
            'order_number'   => $o->order_number,
            'tracking_token' => $o->tracking_token,
            'is_urgent'      => (bool) $o->is_urgent && $open,
            'customer'       => $o->customer ? [
                'id' => $o->customer->id, 'name' => $o->customer->name,
                'phone' => $o->customer->phone, 'address' => $o->customer->address,
            ] : null,
            'items_summary'  => $items->map(fn ($i) => $i->product_type . ' ×' . max(1, (int) $i->quantity))->implode(', '),
            'items_count'    => $items->sum(fn ($i) => max(1, (int) $i->quantity)),
            'order_status'   => $status,
            'stage'          => $stage,
            'stage_index'    => $cancelled ? -1 : ($stageIdx === false ? 0 : $stageIdx),
            'next_stage'     => $next,
            'progress'       => [
                'done'  => $assigns->where('work_status', 'completed')->count(),
                'total' => $assigns->count(),
            ],
            'dorji_names'    => $dorjis,
            'order_date'     => $o->order_date?->toDateString(),
            'delivery_date'  => $o->delivery_date?->toDateString(),
            'days_left'      => $open ? $days : null,
            'total_amount'   => (float) $o->total_amount,
            'paid_amount'    => (float) $o->paid_amount,
            'due_amount'     => $due,
            'payment_status' => $o->payment_status,
            'notes'          => $o->notes,
        ];
    }

    public function stats(int $companyId): array
    {
        $today = today()->toDateString();
        $base  = fn () => TailorOrder::where('company_id', $companyId);
        $open  = fn () => $base()->whereNotIn('order_status', self::CLOSED);

        $outstanding = $base()->where('order_status', '!=', 'cancelled')->whereColumn('total_amount', '>', 'paid_amount');

        return [
            'open_orders'        => $open()->count(),
            'ready_for_pickup'   => $base()->where('order_status', 'ready')->count(),
            'due_in_7_days'      => $open()->whereDate('delivery_date', '>=', $today)
                                       ->whereDate('delivery_date', '<=', today()->addDays(7)->toDateString())->count(),
            'overdue'            => $open()->whereDate('delivery_date', '<', $today)->count(),
            'balance_to_collect' => round((float) (clone $outstanding)->sum(DB::raw('total_amount - paid_amount')), 2),
            'balance_orders'     => (clone $outstanding)->count(),
        ];
    }

    public function statusCounts(int $companyId): array
    {
        $raw = TailorOrder::where('company_id', $companyId)
            ->select('order_status', DB::raw('COUNT(*) as n'))->groupBy('order_status')
            ->pluck('n', 'order_status');

        $counts = ['open' => 0, 'new' => 0, 'cutting' => 0, 'stitching' => 0, 'trial' => 0, 'ready' => 0, 'delivered' => 0, 'cancelled' => 0];
        foreach ($raw as $status => $n) {
            $counts[self::stageOf((string) $status)] += (int) $n;
            if (!in_array($status, self::CLOSED, true)) $counts['open'] += (int) $n;
        }
        return $counts;
    }

    /** Full payload for the order-details dialog. */
    public function detail(int $companyId, int $id): array
    {
        $o = TailorOrder::with(['customer', 'items.fabric', 'items.measurement', 'assignments.dorji', 'statusLogs'])
            ->where('company_id', $companyId)->findOrFail($id);

        $payments = DB::table('tailor_payments')
            ->where('company_id', $companyId)->where('order_id', $o->id)
            ->where(fn ($w) => $w->whereNull('kind')->orWhere('kind', 'customer'))
            ->orderBy('payment_date')->orderBy('id')->get();

        $row   = $this->row($o);
        $items = $o->items;
        $stitch = round($items->sum(fn ($i) => max(1, (int) $i->quantity) * (float) $i->stitching_price), 2);
        $fabric = round($items->sum(fn ($i) => (float) $i->fabric_quantity * (float) $i->fabric_unit_price), 2);

        return array_merge($row, [
            'notes'        => $o->notes,
            'fabric_deducted' => (bool) $o->fabric_deducted,
            'payment_status' => $o->payment_status,
            'charges'      => [
                'stitching' => $stitch,
                // Orders created before per-item pricing only carry the order-level figure.
                'stitching_order_level' => (float) $o->stitching_charge,
                'fabric'    => $fabric,
                'urgent'    => (float) $o->urgent_fee,
                'extra'     => (float) $o->extra_charge,
                'discount'  => (float) $o->discount,
                'total'     => (float) $o->total_amount,
                'paid'      => (float) $o->paid_amount,
                'due'       => $row['due_amount'],
            ],
            'items'        => $items->map(fn ($i) => [
                'id'               => $i->id,
                'product_type'     => $i->product_type,
                'quantity'         => max(1, (int) $i->quantity),
                'stitching_price'  => (float) $i->stitching_price,
                'fabric_source'    => $i->fabric_source,
                'own_fabric_note'  => $i->own_fabric_note,
                'fabric'           => $i->fabric ? ['id' => $i->fabric->id, 'name' => $i->fabric->name, 'unit' => $i->fabric->unit] : null,
                'fabric_quantity'  => (float) $i->fabric_quantity,
                'fabric_unit_price'=> (float) $i->fabric_unit_price,
                'fabric_charge'    => round((float) $i->fabric_quantity * (float) $i->fabric_unit_price, 2),
                'line_total'       => round(max(1, (int) $i->quantity) * (float) $i->stitching_price + (float) $i->fabric_quantity * (float) $i->fabric_unit_price, 2),
                'style_options'    => $i->style_options ?? [],
                'notes'            => $i->notes,
                'measurement'      => $i->measurement ? $i->measurement->toArray() : null,
            ])->values()->all(),
            'assignments'  => $o->assignments->map(fn ($a) => [
                'id' => $a->id, 'dorji_id' => $a->dorji_id, 'dorji_name' => $a->dorji?->name,
                'work_status' => $a->work_status, 'dorji_charge' => (float) $a->dorji_charge,
                'expected_completion' => $a->expected_completion?->toDateString(),
                'admin_notes' => $a->admin_notes,
                'task' => $a->task, 'pieces' => (int) ($a->pieces ?: 1),
            ])->values()->all(),
            'payments'     => $payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'payment_method' => $p->payment_method,
                'payment_date' => substr((string) $p->payment_date, 0, 10), 'reference' => $p->reference,
                'notes' => $p->notes, 'payment_type' => $p->payment_type ?? null,
            ])->values()->all(),
            'status_logs'  => $o->statusLogs->map(fn ($l) => [
                'id' => $l->id, 'from_status' => $l->from_status, 'to_status' => $l->to_status,
                'note' => $l->note, 'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** Lookups for the new-order form: garment hints, dorji load, delivery-day load. */
    public function formOptions(int $companyId): array
    {
        // Last stitching price and average fabric per piece per garment, learned from real orders.
        $garments = TailorOrderItem::query()
            ->join('tailor_orders', 'tailor_orders.id', '=', 'tailor_order_items.order_id')
            ->where('tailor_orders.company_id', $companyId)->whereNull('tailor_orders.deleted_at')
            ->select(
                'tailor_order_items.product_type',
                DB::raw('MAX(tailor_order_items.id) as last_id'),
                DB::raw('AVG(CASE WHEN tailor_order_items.fabric_quantity > 0 THEN tailor_order_items.fabric_quantity / GREATEST(tailor_order_items.quantity,1) END) as avg_fabric')
            )->groupBy('tailor_order_items.product_type')->get()
            ->map(function ($g) {
                $last = TailorOrderItem::find($g->last_id);
                return [
                    'product_type'    => $g->product_type,
                    'last_price'      => $last ? (float) $last->stitching_price : 0.0,
                    'avg_fabric_each' => $g->avg_fabric !== null ? round((float) $g->avg_fabric, 2) : 0.0,
                ];
            })->values()->all();

        $openByDorji = TailorAssignment::where('tailor_assignments.company_id', $companyId)
            ->join('tailor_orders', 'tailor_orders.id', '=', 'tailor_assignments.order_id')
            ->whereNotIn('tailor_orders.order_status', self::CLOSED)->whereNull('tailor_orders.deleted_at')
            ->select('tailor_assignments.dorji_id', DB::raw('COUNT(DISTINCT tailor_assignments.order_id) as n'))
            ->groupBy('tailor_assignments.dorji_id')->pluck('n', 'dorji_id');

        $dorjis = TailorDorji::where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get()
            ->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'phone' => $d->phone,
                'speciality' => $d->speciality ?? [],
                'commission_type' => $d->commission_type, 'commission_value' => (float) $d->commission_value,
                'open_orders' => (int) ($openByDorji[$d->id] ?? 0),
            ])->values()->all();

        $dueByDate = TailorOrder::where('company_id', $companyId)->whereNotIn('order_status', self::CLOSED)
            ->whereNotNull('delivery_date')->whereDate('delivery_date', '>=', today()->toDateString())
            ->select(DB::raw('DATE(delivery_date) as d'), DB::raw('COUNT(*) as n'))->groupBy('d')->pluck('n', 'd');

        return [
            'garments'    => $garments,
            'dorjis'      => $dorjis,
            'urgent_fee'  => self::URGENT_FEE,
            'due_by_date' => (object) $dueByDate->all(),
        ];
    }
}
