<?php

namespace App\Services\Tailor;

use App\Models\TailorAssignment;
use App\Models\TailorDorji;

/**
 * Dorjis screen: list with workload (pieces in hand vs max pieces), earnings, unpaid and on-time rate.
 */
class TailorDorjiOverviewService
{
    public const OPEN_STATUSES = ['assigned', 'in_progress', 'returned'];
    public const DONE_STATUSES = ['completed', 'paid'];

    /** Per-dorji numbers computed from their assignments. */
    private function metrics(int $companyId, array $dorjiIds): array
    {
        if (!$dorjiIds) return [];
        $assignments = TailorAssignment::with('order:id,delivery_date')
            ->where('company_id', $companyId)->whereIn('dorji_id', $dorjiIds)->get();
        $month = now()->format('Y-m');
        $out = [];
        foreach ($dorjiIds as $id) {
            $out[$id] = ['pieces_in_hand' => 0, 'active_orders' => 0, 'earned_month' => 0.0, 'unpaid' => 0.0,
                         'timed' => 0, 'on_time' => 0, 'total_jobs' => 0];
        }
        $orders = [];
        foreach ($assignments as $a) {
            $m = &$out[$a->dorji_id];
            $m['total_jobs']++;
            if (in_array($a->work_status, self::OPEN_STATUSES, true)) {
                $m['pieces_in_hand'] += (int) $a->pieces;
                $orders[$a->dorji_id][$a->order_id] = true;
            }
            if (in_array($a->work_status, self::DONE_STATUSES, true)) {
                if ($a->completed_at && $a->completed_at->format('Y-m') === $month) $m['earned_month'] += (float) $a->dorji_charge;
                $due = $a->order?->delivery_date;
                if ($a->completed_at && $due) {
                    $m['timed']++;
                    if ($a->completed_at->toDateString() <= $due->toDateString()) $m['on_time']++;
                }
            }
            if ($a->work_status === 'completed') $m['unpaid'] += max(0, (float) $a->dorji_charge - (float) $a->paid_amount);
            unset($m);
        }
        foreach ($out as $id => &$m) $m['active_orders'] = count($orders[$id] ?? []);
        return $out;
    }

    public function format(TailorDorji $d, array $m): array
    {
        $cap = (int) ($d->max_pieces ?? 0);
        $arr = $d->toArray();
        return array_merge($arr, [
            'speciality'     => $d->speciality ?? [],
            'max_pieces'     => $cap ?: null,
            'pieces_in_hand' => $m['pieces_in_hand'] ?? 0,
            'active_orders'  => $m['active_orders'] ?? 0,
            'workload_pct'   => $cap ? (int) round(($m['pieces_in_hand'] ?? 0) / $cap * 100) : 0,
            'earned_month'   => round($m['earned_month'] ?? 0, 2),
            'unpaid'         => round($m['unpaid'] ?? 0, 2),
            'on_time_rate'   => !empty($m['timed']) ? (int) round($m['on_time'] / $m['timed'] * 100) : null,
            'total_jobs'     => $m['total_jobs'] ?? 0,
        ]);
    }

    public function overview(int $companyId, array $f = []): array
    {
        $q = TailorDorji::where('company_id', $companyId);
        if (!empty($f['status']) && $f['status'] !== 'all') $q->where('status', $f['status']);
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $q->where(fn($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s)->orWhere('speciality', 'like', $s));
        }
        $limit = min(max((int) ($f['limit'] ?? 12), 1), 100);
        $page  = max(1, (int) ($f['page'] ?? 1));
        $res   = $q->orderBy('name')->paginate($limit, ['*'], 'page', $page);

        $metrics = $this->metrics($companyId, $res->pluck('id')->all());
        $rows = $res->getCollection()->map(fn($d) => $this->format($d, $metrics[$d->id] ?? []))->values()->all();

        // Stats across every dorji (not just this page).
        $all = TailorDorji::where('company_id', $companyId)->get(['id', 'status', 'max_pieces']);
        $allM = $this->metrics($companyId, $all->pluck('id')->all());
        $active = $all->where('status', 'active');
        $timed = array_sum(array_column($allM, 'timed'));
        $onTime = array_sum(array_column($allM, 'on_time'));

        return [
            'rows' => $rows,
            'pagination' => ['page' => $res->currentPage(), 'per_page' => $res->perPage(), 'total' => $res->total(), 'last_page' => $res->lastPage()],
            'stats' => [
                'active'         => $active->count(),
                'on_leave'       => $all->where('status', 'on_leave')->count(),
                'inactive'       => $all->where('status', 'inactive')->count(),
                'total'          => $all->count(),
                'pieces_in_hand' => array_sum(array_column($allM, 'pieces_in_hand')),
                'capacity'       => (int) $active->sum('max_pieces'),
                'unpaid'         => round(array_sum(array_column($allM, 'unpaid')), 2),
                'on_time_rate'   => $timed ? (int) round($onTime / $timed * 100) : null,
            ],
        ];
    }

    public function show(int $companyId, TailorDorji $d): array
    {
        return $this->format($d, $this->metrics($companyId, [$d->id])[$d->id] ?? []);
    }

    /** @return string|null error message when the dorji cannot be deleted */
    public function deleteBlocker(int $companyId, TailorDorji $d): ?string
    {
        $n = TailorAssignment::where('company_id', $companyId)->where('dorji_id', $d->id)->count();
        return $n ? "{$d->name} has {$n} assignment(s). Deactivate instead." : null;
    }
}
