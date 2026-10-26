<?php

namespace App\Repositories\Eloquent;

use App\Models\Sell;
use App\Repositories\Contracts\ISellRepository;
use Illuminate\Support\Facades\DB;

class SellRepository implements ISellRepository
{
    public function __construct(private readonly Sell $model)
    {
    }

    /**
     * Find all sells for a company with filters and pagination
     */
    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->buildListQuery($companyId, $filters)
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments', 'shippingMethodModel']);

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'order_time';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir)->orderBy('id', 'desc');

        // Handle limit vs pagination
        if (!empty($filters['limit'])) {
            $limit = min($filters['limit'], 100);
            return $query->limit($limit)->get();
        }

        // Pagination
        $perPage = min($filters['per_page'] ?? 10, 100);
        return $query->paginate($perPage);
    }

    /**
     * Count and revenue total of every sell matching the list filters (ignores pagination).
     * Cancelled sells are left out of the total unless the Cancelled status is being listed.
     */
    public function summarize(int $companyId, array $filters): array
    {
        $query = $this->buildListQuery($companyId, $filters);
        $count = (clone $query)->count();
        $listingCancelled = strtolower((string) ($filters['status'] ?? '')) === 'cancelled';
        $total = $listingCancelled
            ? (float) (clone $query)->sum('amount')
            : (float) (clone $query)->where('status', '!=', 'Cancelled')->sum('amount');

        return ['count' => $count, 'total' => $total];
    }

    /**
     * Base query with all list filters applied (no eager loads, ordering or pagination).
     */
    private function buildListQuery(int $companyId, array $filters, bool $applyStatus = true)
    {
        $query = $this->model
            ->where('company_id', $companyId);

        // Search by customer name or invoice number ("customer" is an alias of "search")
        $searchTerm = !empty($filters['search']) ? $filters['search'] : ($filters['customer'] ?? null);
        if (!empty($searchTerm) && is_string($searchTerm)) {
            $search = $searchTerm;
            // Remove leading # if present (in case user copies with # prefix)
            $search = ltrim($search, '#');
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('invoice_no', 'like', "%{$search}%")
                  ->orWhere('shipping_phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($applyStatus && !empty($filters['status']) && $filters['status'] !== 'all') {
            $this->applyStatusTab($query, (string) $filters['status']);
        }

        // Filter by fulfillment: shipped = handed to a courier and not yet delivered
        if (!empty($filters['fulfillment']) && in_array($filters['fulfillment'], ['shipped', 'packed'], true)) {
            $query->where('fulfillment_status', $filters['fulfillment'])->where('status', '!=', 'Delivered');
        }

        // Filter by payment state: paid | partial | due | unpaid (= due + partial)
        if (!empty($filters['payment']) && $filters['payment'] !== 'all') {
            match ($filters['payment']) {
                'paid' => $query->where('payment_status', 'paid'),
                'partial' => $query->where('payment_status', 'partially_paid'),
                'due' => $query->where('payment_status', '!=', 'paid')->where('payment_status', '!=', 'partially_paid'),
                'unpaid' => $query->where('payment_status', 'pending')->where('status', '!=', 'Cancelled'),
                default => null,
            };
        }

        // Filter by payment method
        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $query->where('method', $filters['method']);
        }

        // Filter by source (sales = manual | pos | online = storefront); raw values also accepted
        if (!empty($filters['source']) && $filters['source'] !== 'all') {
            $source = ['sales' => 'manual', 'online' => 'storefront'][$filters['source']] ?? $filters['source'];
            $query->where('source', $source);
        }

        // Filter by customer ID
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // Filter by start date (order_time >= date)
        if (!empty($filters['start_date'])) {
            $query->where('order_time', '>=', $filters['start_date']);
        }

        // Filter by end date (order_time <= end of day)
        if (!empty($filters['end_date'])) {
            $query->where('order_time', '<=', $filters['end_date'] . ' 23:59:59');
        }

        return $query;
    }

    /**
     * Apply a derived order tab (or legacy raw status) to a query.
     */
    private function applyStatusTab($query, string $tab): void
    {
        switch (strtolower($tab)) {
            case 'pending':
                $query->where('status', 'Pending');
                break;
            case 'confirmed':
                $query->where('status', 'Processing')
                    ->where(fn ($q) => $q->whereNull('fulfillment_status')->orWhereNotIn('fulfillment_status', ['packed', 'shipped']))
                    ->whereNull('shipped_at');
                break;
            case 'packed':
                $query->where('status', 'Processing')->where('fulfillment_status', 'packed')->whereNull('shipped_at');
                break;
            case 'shipped':
                $query->where('status', 'Processing')
                    ->where(fn ($q) => $q->where('fulfillment_status', 'shipped')->orWhereNotNull('shipped_at'));
                break;
            case 'delivered':
                $query->where('status', 'Delivered');
                break;
            case 'completed':
                $query->where('status', 'Delivered')->where('payment_status', 'paid');
                break;
            case 'cancelled':
                $query->where('status', 'Cancelled');
                break;
            case 'processing':
                $query->where('status', 'Processing');
                break;
            default:
                $query->where('status', $tab);
        }
    }

    /**
     * Per-tab counts respecting every list filter except status.
     */
    public function tabCounts(int $companyId, array $filters): array
    {
        $counts = ['all' => (clone $this->buildListQuery($companyId, $filters, false))->count()];
        foreach (['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'completed', 'cancelled'] as $tab) {
            $q = $this->buildListQuery($companyId, $filters, false);
            $this->applyStatusTab($q, $tab);
            $counts[$tab] = $q->count();
        }
        return $counts;
    }

    /**
     * Company-wide KPI cards for the orders page (ignores list filters).
     */
    public function kpis(int $companyId): array
    {
        $base = fn () => $this->model->where('company_id', $companyId);
        $tab = function (string $t) use ($base) {
            $q = $base();
            $this->applyStatusTab($q, $t);
            return $q->count();
        };
        $live = fn () => $base()->where('status', '!=', 'Cancelled');

        return [
            'totalOrders' => $base()->count(),
            'last30Days' => $base()->where('order_time', '>=', now()->subDays(30))->count(),
            'pending' => $tab('pending'),
            'confirmedOrPacked' => $tab('confirmed') + $tab('packed'),
            'shipped' => $tab('shipped'),
            'delivered' => $tab('delivered'),
            'revenue' => (float) $live()->sum('amount'),
            'dueToCollect' => (float) $live()->sum('due_amount'),
            'unpaidCount' => $live()->where('due_amount', '>', 0)->count(),
        ];
    }

    /**
     * Find a sell by ID within a company scope
     */
    public function findByIdAndCompany(int $id, int $companyId): ?Sell
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments', 'shippingMethodModel'])
            ->find($id);
    }

    /**
     * Find a sell by invoice number within a company scope
     */
    public function findByInvoiceAndCompany(string $invoiceNo, int $companyId): ?Sell
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('invoice_no', $invoiceNo)
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments', 'shippingMethodModel'])
            ->first();
    }

    /**
     * Create a new sell
     */
    public function create(array $data): Sell
    {
        return $this->model->create($data);
    }

    /**
     * Update a sell
     */
    public function update(int $id, array $data): Sell
    {
        $sell = $this->model->findOrFail($id);
        $sell->fill($data)->save();
        return $sell->fresh(['customer', 'shippingAddress', 'coupon', 'items', 'shipments']);
    }

    /**
     * Delete a sell
     */
    public function delete(int $id): bool
    {
        $sell = $this->model->findOrFail($id);
        return $sell->delete();
    }

    /**
     * Get statistics for sells
     */
    public function getStats(int $companyId, ?string $source = null): array
    {
        $stats = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->when($source && $source !== 'all', fn($q) => $q->where('source', $source))
            ->where('status', '!=', 'Cancelled')
            ->selectRaw(
                'COUNT(*) as total_sells,
                 SUM(amount) as total_revenue,
                 SUM(total_cost) as total_cost,
                 SUM(gross_profit) as gross_profit,
                 COALESCE(SUM(CASE WHEN fulfillment_status = "shipped" AND status != "Delivered" THEN 1 ELSE 0 END), 0) as shipped_orders,
                 COALESCE(SUM(CASE WHEN fulfillment_status = "packed" AND status != "Delivered" THEN 1 ELSE 0 END), 0) as packed_orders,
                 COALESCE(SUM(due_amount), 0) as total_due,
                 COALESCE(SUM(CASE WHEN due_amount > 0 THEN 1 ELSE 0 END), 0) as unpaid_count,
                 (SUM(gross_profit) / SUM(amount) * 100) as gp_margin_percent,
                 SUM(CASE WHEN status = "Pending" THEN 1 ELSE 0 END) as pending_orders,
                 SUM(CASE WHEN status = "Processing" THEN 1 ELSE 0 END) as processing_orders,
                 SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) as delivered_orders'
            )
            ->first();

        $cancelled = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'Cancelled')
            ->when($source && $source !== 'all', fn($q) => $q->where('source', $source))
            ->count();

        return [
            'cancelledOrders' => (int) $cancelled,
            'totalSells' => (int) ($stats->total_sells ?? 0),
            'totalRevenue' => (float) ($stats->total_revenue ?? 0),
            'totalCost' => (float) ($stats->total_cost ?? 0),
            'grossProfit' => (float) ($stats->gross_profit ?? 0),
            'shippedOrders' => (int) ($stats->shipped_orders ?? 0),
            'packedOrders' => (int) ($stats->packed_orders ?? 0),
            'totalDue' => (float) ($stats->total_due ?? 0),
            'unpaidCount' => (int) ($stats->unpaid_count ?? 0),
            'gpMarginPercent' => (float) ($stats->gp_margin_percent ?? 0),
            'pendingOrders' => (int) ($stats->pending_orders ?? 0),
            'processingOrders' => (int) ($stats->processing_orders ?? 0),
            'deliveredOrders' => (int) ($stats->delivered_orders ?? 0),
        ];
    }

    public function getWeeklyOrders(int $companyId): array
    {
        $startOfWeek = \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY);
        $endOfWeek   = \Carbon\Carbon::now()->endOfWeek(\Carbon\Carbon::SUNDAY);

        $rows = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->selectRaw('DAYOFWEEK(created_at) as dow, COUNT(*) as cnt')
            ->groupBy('dow')
            ->get()
            ->keyBy('dow');

        // DAYOFWEEK: 1=Sun,2=Mon,...,7=Sat — map to Mon-Sun order
        $order = [2, 3, 4, 5, 6, 7, 1];
        $result = [];
        foreach ($order as $dow) {
            $result[] = (int) ($rows[$dow]->cnt ?? 0);
        }
        return $result;
    }

    public function getMonthlyRevenue(int $companyId): array
    {
        $year = \Carbon\Carbon::now()->year;

        $rows = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereYear('created_at', $year)
            ->selectRaw('MONTH(created_at) as month, SUM(amount) as revenue')
            ->groupBy('month')
            ->get()
            ->keyBy('month');

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $result[] = round((float) ($rows[$m]->revenue ?? 0), 2);
        }
        return $result;
    }

    /**
     * Check if invoice number exists for company
     */
    public function invoiceExists(string $invoiceNo, int $companyId): bool
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('invoice_no', $invoiceNo)
            ->exists();
    }

    /**
     * Get sells with outstanding dues for a company, with aging buckets
     */
    public function getCustomerDues(int $companyId, array $filters): mixed
    {
        $query = $this->buildCustomerDuesQuery($companyId, $filters);

        $query->orderBy('order_time', 'asc');

        $perPage = min($filters['per_page'] ?? 10, 100);
        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(function ($sell) {
            $daysOld = $sell->order_time ? $sell->order_time->diffInDays(now()) : 0;

            if ($daysOld < 30) {
                $bucket = 'current';
            } elseif ($daysOld < 60) {
                $bucket = '30-59';
            } elseif ($daysOld < 90) {
                $bucket = '60-89';
            } else {
                $bucket = '90+';
            }

            $sell->setAttribute('aging_bucket', $bucket);
            $sell->setAttribute('days_old', $daysOld);

            return $sell;
        });

        return $paginated;
    }

    /**
     * Compute summary aggregates (totalDue, totalOverdue, count) for customer dues,
     * scoped by the same filters as getCustomerDues() but without pagination.
     */
    public function getCustomerDuesSummary(int $companyId, array $filters): array
    {
        $totalsQuery = $this->buildCustomerDuesQuery($companyId, $filters);
        $totals = $totalsQuery->selectRaw('COALESCE(SUM(due_amount), 0) as total_due, COUNT(*) as cnt')->first();

        $overdueQuery = $this->buildCustomerDuesQuery($companyId, $filters);
        $overdueQuery->whereRaw('DATEDIFF(CURDATE(), order_time) >= 30');
        $overdueTotal = $overdueQuery->selectRaw('COALESCE(SUM(due_amount), 0) as total_overdue')->value('total_overdue');

        return [
            'totalDue' => (float) $totals->total_due,
            'totalOverdue' => (float) $overdueTotal,
            'count' => (int) $totals->cnt,
        ];
    }

    /**
     * Build the base query (filters applied, no ordering/pagination) shared by
     * getCustomerDues() and getCustomerDuesSummary().
     */
    private function buildCustomerDuesQuery(int $companyId, array $filters)
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('due_amount', '>', 0);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('invoice_no', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
