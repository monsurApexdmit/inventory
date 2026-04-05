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
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments']);

        // Search by customer name or invoice number
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            // Remove leading # if present (in case user copies with # prefix)
            $search = ltrim($search, '#');
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('invoice_no', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // Filter by payment method
        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $query->where('method', $filters['method']);
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

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'order_time';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

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
     * Find a sell by ID within a company scope
     */
    public function findByIdAndCompany(int $id, int $companyId): ?Sell
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments'])
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
            ->with(['customer', 'shippingAddress', 'coupon', 'items', 'shipments'])
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
    public function getStats(int $companyId): array
    {
        $stats = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw(
                'COUNT(*) as total_sells,
                 SUM(amount) as total_revenue,
                 SUM(total_cost) as total_cost,
                 SUM(gross_profit) as gross_profit,
                 (SUM(gross_profit) / SUM(amount) * 100) as gp_margin_percent,
                 SUM(CASE WHEN status = "Pending" THEN 1 ELSE 0 END) as pending_orders,
                 SUM(CASE WHEN status = "Processing" THEN 1 ELSE 0 END) as processing_orders,
                 SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) as delivered_orders'
            )
            ->first();

        return [
            'totalSells' => (int) ($stats->total_sells ?? 0),
            'totalRevenue' => (float) ($stats->total_revenue ?? 0),
            'totalCost' => (float) ($stats->total_cost ?? 0),
            'grossProfit' => (float) ($stats->gross_profit ?? 0),
            'gpMarginPercent' => (float) ($stats->gp_margin_percent ?? 0),
            'pendingOrders' => (int) ($stats->pending_orders ?? 0),
            'processingOrders' => (int) ($stats->processing_orders ?? 0),
            'deliveredOrders' => (int) ($stats->delivered_orders ?? 0),
        ];
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
}
