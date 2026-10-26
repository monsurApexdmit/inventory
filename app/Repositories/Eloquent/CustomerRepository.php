<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\ICustomerRepository;
use Illuminate\Support\Facades\DB;

class CustomerRepository implements ICustomerRepository
{
    public function __construct(private readonly Customer $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role'])
            ->withCount(['sells as total_orders' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }])
            ->withSum(['sells as total_spent' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'amount')
            ->withSum(['sells as due_amount' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'due_amount')
            ->withMax(['sells as last_order_at' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'order_time')
            ->addSelect(DB::raw(
                "exists(select 1 from customers c2 where c2.phone = customers.phone and c2.id != customers.id"
                . " and c2.company_id = {$companyId} and c2.deleted_at is null and customers.phone is not null and customers.phone != '') as dup_phone"
            ));

        if (!empty($filters['segment']) && $filters['segment'] !== 'all') {
            $this->applySegment($query, $filters['segment'], $companyId);
        }

        $this->applyFilters($query, $filters);

        match ($filters['sort'] ?? 'recent') {
            'name' => $query->orderBy('name'),
            'spent' => $query->orderByDesc('total_spent'),
            'orders' => $query->orderByDesc('total_orders'),
            'due' => $query->orderByDesc('due_amount'),
            default => $query->orderByDesc('created_at'),
        };

        $limit = max(1, min((int) ($filters['limit'] ?? 10), 2000));
        return $query->paginate($limit);
    }

    /** Shared by the list and the SMS-numbers endpoint. */
    public function applyFilters($query, array $filters): void
    {
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('business_name', 'like', '%' . $search . '%');
            });
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $group = $filters['group'] ?? null;
        $type = $filters['type'] ?? null;
        // Legacy callers sent the retail/wholesale group as `type`.
        if ($type && in_array($type, ['retail', 'wholesale', 'vip'], true)) {
            $group = $group ?: $type;
            $type = null;
        }
        if ($group && $group !== 'all') {
            $query->where('customer_group', $group);
        }
        if ($type && $type !== 'all') {
            $query->where('type', $type);
        }

        if (filter_var($filters['smsOptIn'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where('sms_opt_in', true)->whereNotNull('phone')->where('phone', '!=', '');
        }
    }

    public function getCounts(int $companyId): array
    {
        $r = $this->model->where('company_id', $companyId)->selectRaw("
            COUNT(*) as total,
            SUM(status = 'active') as active,
            SUM(status = 'inactive') as inactive,
            SUM(customer_group = 'retail') as retail,
            SUM(customer_group = 'wholesale') as wholesale,
            SUM(customer_group = 'vip') as vip,
            SUM(type = 'individual') as individual,
            SUM(type = 'business') as business
        ")->first();

        return [
            'total' => (int) $r->total,
            'active' => (int) $r->active,
            'inactive' => (int) $r->inactive,
            'byGroup' => ['retail' => (int) $r->retail, 'wholesale' => (int) $r->wholesale, 'vip' => (int) $r->vip],
            'byType' => ['individual' => (int) $r->individual, 'business' => (int) $r->business],
        ];
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Customer
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role'])
            ->withCount(['sells as total_orders' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }])
            ->withSum(['sells as total_spent' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'amount')
            ->withSum(['sells as due_amount' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'due_amount')
            ->withMax(['sells as last_order_at' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            }], 'order_time')
            ->find($id);
    }

    public function findByEmailAndCompany(string $email, int $companyId): ?Customer
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->first();
    }

    public function create(array $data): Customer
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Customer
    {
        $customer = $this->model->findOrFail($id);
        $customer->fill($data)->save();

        return $customer;
    }

    public function delete(int $id): bool
    {
        $customer = $this->model->findOrFail($id);

        return (bool) $customer->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN type = "individual" THEN 1 ELSE 0 END) as individuals,
                SUM(CASE WHEN type = "business" THEN 1 ELSE 0 END) as businesses
            ')
            ->first();

        return array_merge($this->getOverview($companyId), [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
            'individuals' => (int) ($stats->individuals ?? 0),
            'businesses' => (int) ($stats->businesses ?? 0),
        ]);
    }


    /** Per-customer aggregates as correlated subqueries (soft-deleted sells excluded). */
    private function sellSub(string $expr, int $companyId): string
    {
        return "(select {$expr} from sells s where s.customer_id = customers.id and s.company_id = {$companyId} and s.deleted_at is null)";
    }

    /**
     * Segment filters used by the customer tabs:
     * loyal (5+ orders, ordered in the last 30 days), risk (has orders, none in 30+ days),
     * due (owes money), never (no orders), dup (phone shared with another customer).
     */
    private function applySegment($query, string $segment, int $companyId): void
    {
        $orders = $this->sellSub('count(*)', $companyId);
        $last = $this->sellSub('max(order_time)', $companyId);
        $due = $this->sellSub('coalesce(sum(due_amount), 0)', $companyId);

        match ($segment) {
            'loyal' => $query->whereRaw("{$orders} >= 5")->whereRaw("{$last} >= ?", [now()->subDays(30)]),
            'risk' => $query->whereRaw("{$orders} > 0")->whereRaw("{$last} < ?", [now()->subDays(30)]),
            'due' => $query->whereRaw("{$due} > 0"),
            'never' => $query->whereRaw("{$orders} = 0"),
            'dup' => $query->whereNotNull('phone')->where('phone', '!=', '')
                ->whereIn('phone', fn($q) => $q->select('phone')->from('customers')
                    ->where('company_id', $companyId)->whereNull('deleted_at')
                    ->whereNotNull('phone')->where('phone', '!=', '')
                    ->groupBy('phone')->havingRaw('count(*) > 1')),
            default => null,
        };
    }

    /** Dashboard-style summary for the customers page. */
    public function getOverview(int $companyId): array
    {
        $base = $this->model->where('company_id', $companyId)->whereNull('deleted_at');
        $orders = $this->sellSub('count(*)', $companyId);
        $spent = $this->sellSub('coalesce(sum(amount), 0)', $companyId);
        $last = $this->sellSub('max(order_time)', $companyId);
        $due = $this->sellSub('coalesce(sum(due_amount), 0)', $companyId);
        $cutoff = now()->subDays(30);

        $row = (clone $base)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN YEAR(created_at) = ? AND MONTH(created_at) = ? THEN 1 ELSE 0 END) as added_this_month,
            SUM(CASE WHEN {$orders} >= 1 THEN 1 ELSE 0 END) as with_orders,
            SUM(CASE WHEN {$orders} >= 2 THEN 1 ELSE 0 END) as repeat_buyers,
            SUM(CASE WHEN {$orders} = 0 THEN 1 ELSE 0 END) as never,
            SUM(CASE WHEN {$orders} >= 5 AND {$last} >= ? THEN 1 ELSE 0 END) as loyal,
            SUM(CASE WHEN {$orders} > 0 AND {$last} < ? THEN 1 ELSE 0 END) as at_risk,
            SUM(CASE WHEN {$due} > 0 THEN 1 ELSE 0 END) as owing,
            COALESCE(SUM({$due}), 0) as total_due,
            COALESCE(SUM(CASE WHEN {$orders} >= 1 THEN {$spent} ELSE 0 END), 0) as spent_with_orders
        ", [now()->year, now()->month, $cutoff, $cutoff])->first();

        $duplicates = $this->model->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->whereIn('phone', fn($q) => $q->select('phone')->from('customers')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->groupBy('phone')->havingRaw('count(*) > 1'))
            ->count();

        $withOrders = (int) $row->with_orders;

        return [
            'total' => (int) $row->total,
            'addedThisMonth' => (int) $row->added_this_month,
            'withOrders' => $withOrders,
            'repeatBuyers' => (int) $row->repeat_buyers,
            'repeatRatePct' => $withOrders > 0 ? round(((int) $row->repeat_buyers) / $withOrders * 100) : 0,
            'avgLifetimeValue' => $withOrders > 0 ? round(((float) $row->spent_with_orders) / $withOrders, 2) : 0,
            'owing' => (int) $row->owing,
            'totalDue' => (float) $row->total_due,
            'atRisk' => (int) $row->at_risk,
            'loyal' => (int) $row->loyal,
            'never' => (int) $row->never,
            'duplicates' => $duplicates,
        ];
    }
}
