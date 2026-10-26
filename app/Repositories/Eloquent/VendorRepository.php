<?php

namespace App\Repositories\Eloquent;

use App\Models\Vendor;
use App\Repositories\Contracts\IVendorRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class VendorRepository implements IVendorRepository
{
    public function __construct(private readonly Vendor $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('tin', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Vendor
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role'])
            ->find($id);
    }

    public function findByEmailAndCompany(string $email, int $companyId): ?Vendor
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->first();
    }

    public function create(array $data): Vendor
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Vendor
    {
        $vendor = $this->model->findOrFail($id);
        $vendor->fill($data)->save();

        return $vendor;
    }

    public function delete(int $id): bool
    {
        $vendor = $this->model->findOrFail($id);

        return (bool) $vendor->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
        ];
    }

    private const COUNTED = "('sent','partial','received')";

    /**
     * Vendors query joined with one grouped purchase-order aggregate (no N+1).
     * Adds: purchased, po_count, open_po_count, po_due, is_overdue, owed.
     */
    private function aggregatedQuery(int $companyId): Builder
    {
        $c = self::COUNTED;
        $agg = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->groupBy('vendor_id')
            ->selectRaw("vendor_id,
                COALESCE(SUM(CASE WHEN status IN {$c} THEN total_amount END), 0) AS purchased,
                COALESCE(SUM(status IN {$c}), 0) AS po_count,
                COALESCE(SUM(status IN ('draft','sent','partial')), 0) AS open_po_count,
                COALESCE(SUM(CASE WHEN status IN {$c} AND due_amount > 0 THEN due_amount END), 0) AS po_due,
                COALESCE(MAX(status IN ('partial','received') AND due_amount > 0 AND expected_date IS NOT NULL AND expected_date < CURDATE()), 0) AS is_overdue");

        return $this->model->newQuery()
            ->where('vendors.company_id', $companyId)
            ->leftJoinSub($agg, 'agg', 'agg.vendor_id', '=', 'vendors.id')
            ->select('vendors.*')
            ->selectRaw('COALESCE(agg.purchased, 0) AS purchased')
            ->selectRaw('COALESCE(agg.po_count, 0) AS po_count')
            ->selectRaw('COALESCE(agg.open_po_count, 0) AS open_po_count')
            ->selectRaw('COALESCE(agg.po_due, 0) AS po_due')
            ->selectRaw('COALESCE(agg.is_overdue, 0) AS is_overdue')
            ->selectRaw('(vendors.opening_balance + COALESCE(agg.po_due, 0)) AS owed');
    }

    public function overview(int $companyId, array $filters): array
    {
        $query = $this->aggregatedQuery($companyId);

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('vendors.name', 'like', $term)
                    ->orWhere('vendors.contact_person', 'like', $term)
                    ->orWhere('vendors.phone', 'like', $term)
                    ->orWhere('vendors.email', 'like', $term)
                    ->orWhere('vendors.description', 'like', $term)
                    ->orWhere('vendors.tin', 'like', $term);
            });
        }

        $status = $filters['status'] ?? 'all';
        if ($status === 'owe') {
            $query->whereRaw('(vendors.opening_balance + COALESCE(agg.po_due, 0)) > 0');
        } elseif (in_array($status, ['Active', 'Inactive', 'Blocked'], true)) {
            $query->where('vendors.status', $status);
        }

        $total = (clone $query)->toBase()->getCountForPagination();

        match ($filters['sort'] ?? 'purchased') {
            'owed' => $query->orderByRaw('owed DESC'),
            'name' => $query->orderBy('vendors.name'),
            'recent' => $query->orderByDesc('vendors.created_at')->orderByDesc('vendors.id'),
            default => $query->orderByRaw('purchased DESC'),
        };
        $query->orderBy('vendors.name')->orderBy('vendors.id');

        $limit = max(1, min((int) ($filters['limit'] ?? 100), 500));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return [
            'rows' => $query->forPage($page, $limit)->get(),
            'total' => $total,
        ];
    }

    public function findWithAggregates(int $id, int $companyId): ?Vendor
    {
        return $this->aggregatedQuery($companyId)->where('vendors.id', $id)->first();
    }

    public function itemsSummaries(array $vendorIds): array
    {
        if (!$vendorIds) {
            return [];
        }

        $rows = DB::table('purchase_order_items as i')
            ->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('po.vendor_id', $vendorIds)
            ->where('po.status', '!=', 'cancelled')
            ->groupBy('po.vendor_id', 'p.id', 'p.name')
            ->orderByRaw('MIN(i.id)')
            ->get(['po.vendor_id', 'p.name']);

        $names = [];
        foreach ($rows as $r) {
            $names[$r->vendor_id][] = $r->name;
        }

        $out = [];
        foreach ($names as $vendorId => $list) {
            $out[$vendorId] = implode(', ', array_slice($list, 0, 2))
                . (count($list) > 2 ? ' +' . (count($list) - 2) . ' more' : '');
        }

        return $out;
    }

    public function overviewStats(int $companyId): array
    {
        $vendors = $this->aggregatedQuery($companyId)->get();

        $owedRows = $vendors->filter(fn($v) => (float) $v->owed > 0);
        $leads = $vendors->filter(fn($v) => (int) $v->lead_time_days > 0)->pluck('lead_time_days');

        $c = self::COUNTED;
        $po = DB::table('purchase_orders')->where('company_id', $companyId)->selectRaw("
            COALESCE(SUM(CASE WHEN status IN {$c} THEN total_amount END), 0) AS purchased,
            COALESCE(SUM(status IN {$c}), 0) AS po_count,
            COALESCE(SUM(paid_amount), 0) AS paid,
            COALESCE(SUM(status IN ('draft','sent','partial')), 0) AS open_pos,
            COALESCE(SUM(status = 'draft'), 0) AS draft_pos,
            COALESCE(SUM(status = 'sent'), 0) AS ordered_pos")->first();

        $payments = DB::table('due_payments')
            ->where('company_id', $companyId)
            ->where('payable_type', \App\Models\PurchaseOrder::class)
            ->selectRaw('COUNT(*) AS cnt')->value('cnt');
        $openingPayments = DB::table('vendor_payments')->where('company_id', $companyId)
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total')->first();

        $oldest = DB::table('purchase_orders')
            ->where('company_id', $companyId)
            ->whereIn('status', ['partial', 'received'])
            ->where('due_amount', '>', 0)
            ->whereNotNull('expected_date')
            ->whereRaw('expected_date < CURDATE()')
            ->orderBy('expected_date')->orderBy('id')
            ->first(['po_number', 'expected_date']);

        return [
            'total' => $vendors->count(),
            'active' => $vendors->where('status', 'Active')->count(),
            'inactive' => $vendors->where('status', 'Inactive')->count(),
            'blocked' => $vendors->where('status', 'Blocked')->count(),
            'oweCount' => $owedRows->count(),
            'owedSuppliers' => $owedRows->count(),
            'totalOwed' => round((float) $owedRows->sum('owed'), 2),
            'overdueSuppliers' => $vendors->filter(fn($v) => (int) $v->is_overdue === 1)->count(),
            'totalPurchased' => round((float) $po->purchased, 2),
            'poCount' => (int) $po->po_count,
            'totalPaid' => round((float) $po->paid + (float) $openingPayments->total, 2),
            'paymentCount' => (int) $payments + (int) $openingPayments->cnt,
            'openPos' => (int) $po->open_pos,
            'draftPos' => (int) $po->draft_pos,
            'orderedPos' => (int) $po->ordered_pos,
            'avgLeadDays' => $leads->isEmpty() ? null : round((float) $leads->avg(), 1),
            'oldestOverdue' => $oldest ? [
                'poNumber' => $oldest->po_number,
                'expectedDate' => (string) $oldest->expected_date,
            ] : null,
        ];
    }
}
