<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use App\Repositories\Contracts\IExpenseRepository;

class ExpenseRepository implements IExpenseRepository
{
    public function __construct(private readonly Expense $model)
    {
    }

    /**
     * Apply list filters. $scope: 'all' = every filter, 'trend' = only category/method/location/vendor.
     */
    private function applyFilters($query, array $filters, string $scope = 'all')
    {
        $method = $filters['method'] ?? $filters['payment_method'] ?? null;
        $from = $filters['from'] ?? $filters['start_date'] ?? null;
        $to = $filters['to'] ?? $filters['end_date'] ?? null;

        if (!empty($filters['category_id'])) {
            $query->where('expense_category_id', $filters['category_id']);
        }
        if (!empty($method)) {
            $query->where('payment_method', $method);
        }
        if (!empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if ($scope === 'all') {
            match ($filters['tab'] ?? 'all') {
                'rec' => $query->where('is_recurring', true),
                'salary' => $query->where('source', 'salary'),
                'manual' => $query->where('source', '!=', 'salary'),
                default => null,
            };
        }

        if ($scope === 'all' || $scope === 'counts') {
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                        ->orWhere('paid_to', 'like', '%' . $search . '%')
                        ->orWhere('reference_no', 'like', '%' . $search . '%')
                        ->orWhere('notes', 'like', '%' . $search . '%')
                        ->orWhereHas('category', fn ($c) => $c->where('name', 'like', '%' . $search . '%'));
                });
            }
            if (!empty($from)) {
                $query->whereDate('expense_date', '>=', $from);
            }
            if (!empty($to)) {
                $query->whereDate('expense_date', '<=', $to);
            }
        }

        return $query;
    }

    /** Tab counts over all active filters except `tab` itself. */
    public function getTabCounts(int $companyId, array $filters): array
    {
        $q = $this->applyFilters(Expense::query()->where('company_id', $companyId), $filters, 'counts');
        $r = $q->selectRaw("COUNT(*) as c_all, COALESCE(SUM(is_recurring = 1), 0) as c_rec, COALESCE(SUM(source = 'salary'), 0) as c_salary, COALESCE(SUM(source != 'salary'), 0) as c_manual")->first();

        return ['all' => (int) $r->c_all, 'rec' => (int) $r->c_rec, 'salary' => (int) $r->c_salary, 'manual' => (int) $r->c_manual];
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'vendor', 'location']);

        $this->applyFilters($query, $filters);

        $limit = max(1, min((int) ($filters['per_page'] ?? $filters['limit'] ?? 10), 100));
        return $query->orderByDesc('expense_date')->orderByDesc('id')->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Expense
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'vendor', 'location'])
            ->find($id);
    }

    /**
     * KPI block (company-wide, unfiltered) + filtered category breakdown + 6-month trend.
     */
    public function getListStats(int $companyId, array $filters): array
    {
        $today = now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $prevStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $prevStart->copy()->endOfMonth();
        $yearStart = $today->copy()->startOfYear();

        $base = fn () => Expense::query()->where('company_id', $companyId);
        $sum = fn ($q) => round((float) $q->sum('amount'), 2);

        $spent = $sum($base()->whereBetween('expense_date', [$monthStart->toDateString(), $monthEnd->toDateString()]));
        $prev = $sum($base()->whereBetween('expense_date', [$prevStart->toDateString(), $prevEnd->toDateString()]));
        $yearQ = fn () => $base()->whereDate('expense_date', '>=', $yearStart->toDateString())->whereDate('expense_date', '<=', $today->copy()->endOfYear()->toDateString());

        // Active recurring bills (templates)
        $recurring = $base()
            ->where('is_recurring', true)
            ->whereNotNull('recurring_frequency')
            ->where(function ($q) use ($today) {
                $q->whereNull('recurring_end_date')->orWhereDate('recurring_end_date', '>=', $today->toDateString());
            })
            ->get(['id', 'title', 'expense_category_id', 'expense_date', 'amount', 'recurring_frequency', 'recurring_end_date', 'next_due_date'])
            // One bill = latest row per title + category (re-saved/duplicated rows must not double count)
            ->sortByDesc(fn ($e) => $e->expense_date->toDateString() . '|' . str_pad((string) $e->id, 12, '0', STR_PAD_LEFT))
            ->unique(fn ($e) => mb_strtolower(trim($e->title)) . '|' . ($e->expense_category_id ?? ''))
            ->values();

        $recurringMonthly = round($recurring->sum(fn ($e) => (float) $e->amount * Expense::monthlyFactor($e->recurring_frequency)), 2);
        $weekEnd = $today->copy()->addDays(7);
        $billsDue = $recurring
            ->filter(fn ($e) => $e->next_due_date
                && $e->next_due_date->lte($weekEnd)
                && ($e->recurring_end_date === null || $e->next_due_date->lte($e->recurring_end_date)))
            ->sortBy(fn ($e) => $e->next_due_date->toDateString())
            ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'nextDueDate' => $e->next_due_date->format('Y-m-d')])
            ->values()->all();

        $sales = DB::table('sells')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')
            ->whereBetween(DB::raw('COALESCE(order_time, created_at)'), [$monthStart->toDateTimeString(), $monthEnd->copy()->endOfDay()->toDateTimeString()])
            ->selectRaw('COALESCE(SUM(amount), 0) as revenue, COALESCE(SUM(total_cost), 0) as cogs')
            ->first();
        $revenue = round((float) $sales->revenue, 2);
        $cogs = round((float) $sales->cogs, 2);

        // Filtered category breakdown
        $catRows = $this->applyFilters($base(), $filters)
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id as cid, SUM(amount) as amt')
            ->get();
        $cats = ExpenseCategory::where('company_id', $companyId)->get(['id', 'name', 'color'])->keyBy('id');
        $total = (float) $catRows->sum('amt');
        $byCategory = $catRows->map(function ($r) use ($cats, $total) {
            $c = $r->cid ? ($cats[$r->cid] ?? null) : null;
            return [
                'categoryId' => $r->cid ? (int) $r->cid : null,
                'name' => $c?->name ?? 'Uncategorized',
                'color' => $c?->color,
                'amount' => round((float) $r->amt, 2),
                'pct' => $total > 0 ? round((float) $r->amt / $total * 100, 1) : 0,
            ];
        })->sortByDesc('amount')->values()->all();

        // 6-month trend (category/method/location/vendor filters only)
        $trendStart = $monthStart->copy()->subMonthsNoOverflow(5);
        $trendRows = $this->applyFilters($base(), $filters, 'trend')
            ->whereDate('expense_date', '>=', $trendStart->toDateString())
            ->whereDate('expense_date', '<=', $monthEnd->toDateString())
            ->groupByRaw("DATE_FORMAT(expense_date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as ym, SUM(amount) as amt")
            ->pluck('amt', 'ym');
        $trend = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $trendStart->copy()->addMonthsNoOverflow($i);
            $trend[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'amount' => round((float) ($trendRows[$m->format('Y-m')] ?? 0), 2)];
        }

        return [
            'stats' => [
                'spentThisMonth' => $spent,
                'prevMonthSpent' => $prev,
                'deltaPct' => $prev > 0 ? (int) round(($spent - $prev) / $prev * 100) : null,
                'yearTotal' => $sum($yearQ()),
                'yearCount' => (int) $yearQ()->count(),
                'recurringMonthly' => $recurringMonthly,
                'recurringCount' => $recurring->count(),
                'billsDueThisWeek' => $billsDue,
                'monthRevenue' => $revenue,
                'monthCogs' => $cogs,
                'profitAfterExpenses' => round($revenue - $cogs - $spent, 2),
            ],
            'byCategory' => $byCategory,
            'trend' => $trend,
        ];
    }

    /** Up to $limit most recent distinct manual expenses, usable as quick-add templates. */
    public function getTemplates(int $companyId, int $limit = 6): array
    {
        $rows = Expense::query()
            ->where('company_id', $companyId)
            ->where('source', 'manual')
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->limit(200)
            ->get(['title', 'expense_category_id', 'amount', 'payment_method', 'paid_to', 'vendor_id', 'location_id', 'is_recurring', 'recurring_frequency', 'recurring_end_date']);

        $seen = [];
        $out = [];
        foreach ($rows as $e) {
            $key = mb_strtolower(trim($e->title)) . '|' . ($e->expense_category_id ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'title' => $e->title,
                'categoryId' => $e->expense_category_id,
                'amount' => (float) $e->amount,
                'paymentMethod' => $e->payment_method,
                'paidTo' => $e->paid_to,
                'vendorId' => $e->vendor_id,
                'locationId' => $e->location_id,
                'isRecurring' => (bool) $e->is_recurring,
                'recurringFrequency' => $e->is_recurring ? $e->recurring_frequency : null,
                'recurringEndDate' => $e->is_recurring && $e->recurring_end_date ? $e->recurring_end_date->format('Y-m-d') : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Recurring templates whose next occurrence is due (across all companies). */
    public function getDueRecurring(\Carbon\CarbonInterface $today)
    {
        return Expense::query()
            ->where('is_recurring', true)
            ->whereNotNull('recurring_frequency')
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today->toDateString())
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): Expense
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Expense
    {
        $expense = $this->model->findOrFail($id);
        $expense->fill($data)->save();

        return $expense;
    }

    public function delete(int $id): bool
    {
        $expense = $this->model->findOrFail($id);

        return (bool) $expense->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN MONTH(expense_date) = MONTH(CURRENT_DATE()) AND YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END) as total_this_month,
                SUM(CASE WHEN YEAR(expense_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END) as total_this_year
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'totalThisMonth' => (float) ($stats->total_this_month ?? 0),
            'totalThisYear' => (float) ($stats->total_this_year ?? 0),
        ];
    }
}
