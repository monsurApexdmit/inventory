<?php

namespace App\Repositories\Eloquent;

use App\Models\PurchaseOrder;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseOrderRepository implements IPurchaseOrderRepository
{
    public function __construct(private readonly PurchaseOrder $model) {}

    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->filteredQuery($companyId, $filters)
            ->with(['vendor', 'location', 'items.product', 'items.variant', 'payments', 'receipts.location']);

        $perPage = max(1, min((int) ($filters['per_page'] ?? $filters['limit'] ?? 15), 100));
        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /** Company-scoped query with the list filters applied (no eager loads). */
    private function filteredQuery(int $companyId, array $filters)
    {
        $query = $this->model->newQuery()->where('company_id', $companyId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', fn($v) => $v->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('items.product', fn($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status'] === 'ordered' ? 'sent' : $filters['status']);
        }

        if (!empty($filters['payment'])) {
            $map = ['paid' => 'paid', 'partial' => 'partially_paid', 'unpaid' => 'pending'];
            if (isset($map[$filters['payment']])) {
                $query->where('payment_status', $map[$filters['payment']]);
            }
        }

        if (!empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        return $query;
    }

    /** Outstanding amount over every PO matching the filters (cancelled orders excluded). */
    public function sumDue(int $companyId, array $filters): float
    {
        return round((float) $this->filteredQuery($companyId, $filters)
            ->where('status', '<>', 'cancelled')
            ->sum('due_amount'), 2);
    }

    public function findById(int $id, int $companyId): ?PurchaseOrder
    {
        return $this->model->with(['vendor', 'location', 'items.product', 'items.variant', 'payments', 'receipts.location'])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    public function create(array $data): PurchaseOrder
    {
        return $this->model->create($data);
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $po->update($data);
        return $po->fresh(['vendor', 'location', 'items.product', 'items.variant', 'payments', 'receipts.location']);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->model->where('id', $id)->where('company_id', $companyId)->delete();
    }

    public function nextPoNumber(int $companyId): string
    {
        if ($configured = \App\Support\DocumentNumbers::nextPo($companyId)) {
            return $configured;
        }

        $year = date('Y');
        $prefix = "PO-{$year}-";
        $last = $this->model
            ->where('company_id', $companyId)
            ->where('po_number', 'like', "{$prefix}%")
            ->orderByDesc('po_number')
            ->value('po_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getStats(int $companyId): array
    {
        $rows = $this->model->where('company_id', $companyId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS amount')
            ->get()
            ->keyBy('status');

        $count = fn(string $st) => (int) ($rows[$st]->cnt ?? 0);
        $amount = fn(string $st) => round((float) ($rows[$st]->amount ?? 0), 2);

        $totalDue = (float) $this->model->where('company_id', $companyId)
            ->whereIn('status', ['sent', 'partial', 'received'])
            ->where('due_amount', '>', 0)
            ->sum('due_amount');

        $reorderCount = (int) \App\Models\Product::where('company_id', $companyId)
            ->whereColumn('stock', '<=', 'reorder_point')
            ->count();

        return [
            'total'    => (int) $rows->sum('cnt'),
            'draft'    => $count('draft'),
            'sent'     => $count('sent'),
            'partial'  => $count('partial'),
            'received' => $count('received'),
            'cancelled'=> $count('cancelled'),
            'count'    => (int) $rows->sum('cnt'),
            'totalDue' => round($totalDue, 2),
            'reorderCount' => $reorderCount,
            'byStatus' => [
                'draft'     => ['count' => $count('draft'),     'amount' => $amount('draft')],
                'ordered'   => ['count' => $count('sent'),      'amount' => $amount('sent')],
                'partial'   => ['count' => $count('partial'),   'amount' => $amount('partial')],
                'received'  => ['count' => $count('received'),  'amount' => $amount('received')],
                'cancelled' => ['count' => $count('cancelled'), 'amount' => $amount('cancelled')],
            ],
        ];
    }

    public function getVendorDues(int $companyId, array $filters): mixed
    {
        $query = $this->buildVendorDuesQuery($companyId, $filters);

        $query->orderBy('expected_date', 'asc');

        $perPage = min((int) ($filters['per_page'] ?? 10), 100);
        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(function ($po) {
            $refDate = $po->expected_date ?? $po->created_at;
            $daysOld = $refDate ? $refDate->diffInDays(now()) : 0;

            if ($daysOld < 30) {
                $bucket = 'current';
            } elseif ($daysOld < 60) {
                $bucket = '30-59';
            } elseif ($daysOld < 90) {
                $bucket = '60-89';
            } else {
                $bucket = '90+';
            }

            $po->setAttribute('aging_bucket', $bucket);
            $po->setAttribute('days_old', $daysOld);

            return $po;
        });

        return $paginated;
    }

    /**
     * Compute summary aggregates (totalDue, totalOverdue, count) for vendor dues,
     * scoped by the same filters as getVendorDues() but without pagination.
     */
    public function getVendorDuesSummary(int $companyId, array $filters): array
    {
        $totalsQuery = $this->buildVendorDuesQuery($companyId, $filters);
        $totals = $totalsQuery->selectRaw('COALESCE(SUM(due_amount), 0) as total_due, COUNT(*) as cnt')->first();

        $overdueQuery = $this->buildVendorDuesQuery($companyId, $filters);
        $overdueQuery->whereRaw('DATEDIFF(CURDATE(), COALESCE(expected_date, created_at)) >= 30');
        $overdueTotal = $overdueQuery->selectRaw('COALESCE(SUM(due_amount), 0) as total_overdue')->value('total_overdue');

        return [
            'totalDue' => (float) $totals->total_due,
            'totalOverdue' => (float) $overdueTotal,
            'count' => (int) $totals->cnt,
        ];
    }

    /**
     * Build the base query (filters applied, no ordering/pagination) shared by
     * getVendorDues() and getVendorDuesSummary().
     */
    private function buildVendorDuesQuery(int $companyId, array $filters)
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('due_amount', '>', 0)
            ->with(['vendor']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', fn($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        return $query;
    }
}
