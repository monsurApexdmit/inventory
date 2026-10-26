<?php

namespace App\Services\Tailor;

use App\Models\TailorAssignment;
use App\Models\TailorDorji;
use App\Models\TailorOrder;
use App\Models\TailorStatusLog;

/**
 * Assignments screen: filters, pagination, stats, status flow and dorji charge (pieces x rate).
 */
class TailorAssignmentBoardService
{
    private const OPEN = ['assigned', 'in_progress', 'returned'];

    private function base(int $companyId, array $f)
    {
        $q = TailorAssignment::with(['order.customer:id,name,phone', 'order.items:id,order_id,product_type', 'dorji:id,name,status,max_pieces'])
            ->where('company_id', $companyId);
        if (!empty($f['dorji_id'])) $q->where('dorji_id', $f['dorji_id']);
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $q->where(fn($w) => $w->where('task', 'like', $s)
                ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', $s)
                    ->orWhereHas('customer', fn($c) => $c->where('name', 'like', $s)))
                ->orWhereHas('dorji', fn($d) => $d->where('name', 'like', $s)));
        }
        if (!empty($f['due_from'])) $q->whereDate('expected_completion', '>=', $f['due_from']);
        if (!empty($f['due_to']))   $q->whereDate('expected_completion', '<=', $f['due_to']);
        if (!empty($f['open_only'])) $q->whereIn('work_status', self::OPEN);
        return $q;
    }

    public function format(TailorAssignment $a): array
    {
        $arr = $a->toArray();
        $due = $a->expected_completion ?? $a->order?->delivery_date;
        $charge = (float) $a->dorji_charge;
        $arr['order'] = $a->order ? [
            'id' => $a->order->id, 'order_number' => $a->order->order_number,
            'delivery_date' => $a->order->delivery_date?->toDateString(),
            'customer_name' => $a->order->customer?->name,
            'order_status'  => $a->order->order_status,
        ] : null;
        $arr['task'] = $a->task ?: ($a->order ? $a->order->items->pluck('product_type')->filter()->unique()->implode(', ') : null);
        $arr['due_date'] = $due?->toDateString();
        $arr['payable'] = $a->work_status === 'completed' ? max(0, $charge - (float) $a->paid_amount) : 0;
        return $arr;
    }

    public function board(int $companyId, array $f = []): array
    {
        $scoped = $this->base($companyId, $f);
        $counts = ['all' => 0, 'assigned' => 0, 'in_progress' => 0, 'completed' => 0, 'returned' => 0, 'paid' => 0];
        foreach ((clone $scoped)->selectRaw('work_status, count(*) c')->groupBy('work_status')->pluck('c', 'work_status') as $k => $v) {
            $counts[$k] = (int) $v; $counts['all'] += (int) $v;
        }

        $q = clone $scoped;
        if (!empty($f['work_status']) && $f['work_status'] !== 'all') $q->where('work_status', $f['work_status']);
        $limit = min(max((int) ($f['limit'] ?? 10), 1), 100);
        $page  = max(1, (int) ($f['page'] ?? 1));
        $res = $q->orderByDesc('assigned_date')->orderByDesc('id')->paginate($limit, ['*'], 'page', $page);

        $pending = TailorAssignment::where('company_id', $companyId)->where('work_status', 'completed')->get(['dorji_charge', 'paid_amount']);
        $unassigned = $this->openOrders($companyId)->filter(fn($o) => $o['unassigned'])->values();

        return [
            'rows' => $res->getCollection()->map(fn($a) => $this->format($a))->values()->all(),
            'pagination' => ['page' => $res->currentPage(), 'per_page' => $res->perPage(), 'total' => $res->total(), 'last_page' => $res->lastPage()],
            'stats' => [
                'counts'          => $counts,
                'pay_pending'     => round($pending->sum(fn($a) => max(0, $a->dorji_charge - $a->paid_amount)), 2),
                'pay_pending_jobs' => $pending->count(),
                'unassigned_orders' => $unassigned->count(),
                'first_unassigned'  => $unassigned->first(),
            ],
        ];
    }

    /** Open orders (not delivered/cancelled) with a short garment summary. */
    public function openOrders(int $companyId)
    {
        $assigned = TailorAssignment::where('company_id', $companyId)->pluck('order_id')->flip();
        return TailorOrder::with(['customer:id,name', 'items:id,order_id,product_type,quantity'])
            ->where('company_id', $companyId)->whereNotIn('order_status', ['delivered', 'cancelled'])
            ->orderByDesc('id')->get()->map(function ($o) use ($assigned) {
                $groups = $o->items->groupBy('product_type')->map(fn($g) => (int) $g->sum(fn($i) => max(1, (int) ($i->quantity ?? 1))));
                return [
                    'id' => $o->id, 'order_number' => $o->order_number, 'customer_name' => $o->customer?->name,
                    'delivery_date' => $o->delivery_date?->toDateString(),
                    'garments' => $groups->map(fn($n, $t) => $t . " ×{$n}")->implode(', '),
                    'task' => $groups->keys()->implode(', '), 'pieces' => (int) $groups->sum(),
                    'unassigned' => !isset($assigned[$o->id]),
                ];
            });
    }

    public function options(int $companyId): array
    {
        $dorjis = TailorDorji::where('company_id', $companyId)->orderBy('name')->get();
        $hand = TailorAssignment::where('company_id', $companyId)->whereIn('work_status', self::OPEN)
            ->selectRaw('dorji_id, sum(pieces) p')->groupBy('dorji_id')->pluck('p', 'dorji_id');
        return [
            'orders'  => $this->openOrders($companyId)->all(),
            'dorjis'  => $dorjis->map(fn($d) => [
                'id' => $d->id, 'name' => $d->name, 'status' => $d->status, 'max_pieces' => $d->max_pieces,
                'rate_per_piece' => $d->commission_type === 'fixed' ? (float) $d->commission_value : 0,
                'pieces_in_hand' => (int) ($hand[$d->id] ?? 0),
            ])->all(),
        ];
    }

    private function piecesInHand(int $companyId, int $dorjiId, ?int $excludeId = null): int
    {
        return (int) TailorAssignment::where('company_id', $companyId)->where('dorji_id', $dorjiId)
            ->whereIn('work_status', self::OPEN)->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))->sum('pieces');
    }

    /** @throws \DomainException  code 409 => capacity warning (resend with force), 422 => hard error */
    public function create(int $companyId, array $d, bool $force = false): TailorAssignment
    {
        $order = TailorOrder::where('company_id', $companyId)->findOrFail($d['order_id']);
        if (in_array($order->order_status, ['delivered', 'cancelled'], true)) {
            throw new \DomainException("This order is already {$order->order_status}.", 422);
        }
        $dorji = TailorDorji::where('company_id', $companyId)->findOrFail($d['dorji_id']);
        if ($dorji->status !== 'active') throw new \DomainException("{$dorji->name} is not active.", 422);

        $pieces = max(1, (int) ($d['pieces'] ?? 1));
        $cap = (int) $dorji->max_pieces;
        $hand = $this->piecesInHand($companyId, $dorji->id);
        if ($cap && $hand + $pieces > $cap && !$force) {
            throw new \DomainException("{$dorji->name} already has {$hand} piece(s) of {$cap} capacity. Assign {$pieces} more anyway?", 409);
        }

        $hasRate = isset($d['rate_per_piece']);
        $d['pieces'] = $pieces;
        $d['rate_per_piece'] = (float) ($d['rate_per_piece'] ?? 0);
        if ($hasRate) $d['dorji_charge'] = round($pieces * $d['rate_per_piece'], 2);
        $d['company_id'] = $companyId;
        $d['work_status'] = $d['work_status'] ?? 'assigned';
        if ($d['work_status'] === 'completed') $d['completed_at'] = now();
        $a = TailorAssignment::create($d);
        // The order's own dorji: first dorji assigned when none is set yet.
        if (\Illuminate\Support\Facades\Schema::hasColumn('tailor_orders', 'dorji_id')) {
            \Illuminate\Support\Facades\DB::table('tailor_orders')->where('id', $order->id)->whereNull('dorji_id')->update(['dorji_id' => $dorji->id]);
        }
        if (in_array($order->order_status, ['pending', 'measurement_taken'], true)) {
            $order->update(['order_status' => 'assigned']);
        }
        return $a->load(['order.customer', 'order.items', 'dorji']);
    }

    /** @return array{0: TailorAssignment, 1: array} assignment + notices (order_all_done, order_id, order_moved_back) */
    public function update(int $companyId, int $id, array $d, int $userId = 0): array
    {
        $a = TailorAssignment::with('order')->where('company_id', $companyId)->findOrFail($id);
        if ($a->work_status === 'paid') throw new \DomainException('Paid jobs cannot be changed.', 422);
        if (($d['work_status'] ?? null) === 'paid') throw new \DomainException('Pay this job from Tailor payments.', 422);

        if (!empty($d['dorji_id']) && (int) $d['dorji_id'] !== (int) $a->dorji_id) {
            $nd = TailorDorji::where('company_id', $companyId)->findOrFail($d['dorji_id']);
            if ($nd->status !== 'active') throw new \DomainException("{$nd->name} is not active.", 422);
        }

        $pieces = (int) ($d['pieces'] ?? $a->pieces);
        $rate   = (float) ($d['rate_per_piece'] ?? $a->rate_per_piece);
        if (isset($d['pieces']) || isset($d['rate_per_piece'])) $d['dorji_charge'] = round($pieces * $rate, 2);

        $notices = [];
        if (isset($d['work_status']) && $d['work_status'] !== $a->work_status) {
            $d['completed_at'] = $d['work_status'] === 'completed' ? now() : null;
        }
        $a->update($d);

        $order = $a->order;
        if ($order && isset($d['work_status'])) {
            if ($d['work_status'] === 'returned' && $order->order_status === 'ready') {
                $order->update(['order_status' => 'stitching']);
                TailorStatusLog::create(['order_id' => $order->id, 'from_status' => 'ready', 'to_status' => 'stitching',
                    'changed_by' => $userId, 'note' => 'Returned for fixing by dorji job: ' . ($a->task ?? '')]);
                $notices['order_moved_back'] = true;
            }
            if ($d['work_status'] === 'completed' && !in_array($order->order_status, ['ready', 'delivered', 'cancelled', 'pending'], true)) {
                $mine = TailorAssignment::where('order_id', $order->id)->pluck('work_status');
                if ($mine->every(fn($s) => in_array($s, ['completed', 'paid'], true))) {
                    $notices['order_all_done'] = true;
                    $notices['order_id'] = $order->id;
                    $notices['order_number'] = $order->order_number;
                }
            }
        }
        return [$a->fresh(['order.customer', 'order.items', 'dorji']), $notices];
    }

    public function delete(int $companyId, int $id): void
    {
        $a = TailorAssignment::where('company_id', $companyId)->findOrFail($id);
        if ($a->work_status === 'paid') throw new \DomainException('Paid jobs cannot be deleted.', 422);
        $a->delete();
    }
}
