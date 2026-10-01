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
        $query = $this->model->with(['vendor', 'location', 'items.product', 'items.variant'])
            ->where('company_id', $companyId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', fn($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function findById(int $id, int $companyId): ?PurchaseOrder
    {
        return $this->model->with(['vendor', 'location', 'items.product', 'items.variant'])
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
        return $po->fresh(['vendor', 'location', 'items.product', 'items.variant']);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->model->where('id', $id)->where('company_id', $companyId)->delete();
    }

    public function nextPoNumber(int $companyId): string
    {
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
        $base = $this->model->where('company_id', $companyId);

        return [
            'total'    => (clone $base)->count(),
            'draft'    => (clone $base)->where('status', 'draft')->count(),
            'sent'     => (clone $base)->where('status', 'sent')->count(),
            'partial'  => (clone $base)->where('status', 'partial')->count(),
            'received' => (clone $base)->where('status', 'received')->count(),
            'cancelled'=> (clone $base)->where('status', 'cancelled')->count(),
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
