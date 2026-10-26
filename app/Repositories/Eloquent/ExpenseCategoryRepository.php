<?php

namespace App\Repositories\Eloquent;

use App\Models\ExpenseCategory;
use App\Models\Expense;
use App\Repositories\Contracts\IExpenseCategoryRepository;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryRepository implements IExpenseCategoryRepository
{
    public function __construct(private readonly ExpenseCategory $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model->where('company_id', $companyId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('type', 'like', '%' . $search . '%');
            });
        }

        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$includeInactive) {
            $query->where('status', true);
        }

        $limit = min((int) ($filters['per_page'] ?? $filters['limit'] ?? 10), 200);
        $paginated = $query->orderBy('name')->paginate(max($limit, 1));

        $this->attachUsage($companyId, $paginated->getCollection());

        return $paginated;
    }

    /**
     * Attach per-category spend/count (this month and all time) to a collection of categories.
     */
    public function attachUsage(int $companyId, $categories): void
    {
        $ids = $categories->pluck('id')->all();
        $rows = [];
        if ($ids) {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            $rows = Expense::query()
                ->where('company_id', $companyId)
                ->whereIn('expense_category_id', $ids)
                ->groupBy('expense_category_id')
                ->selectRaw(
                    'expense_category_id as cid,
                     COALESCE(SUM(amount), 0) as all_spent, COUNT(*) as all_cnt,
                     COALESCE(SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as m_spent,
                     COALESCE(SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) as m_cnt',
                    [$monthStart, $monthEnd, $monthStart, $monthEnd]
                )
                ->get()->keyBy('cid');
        }

        foreach ($categories as $category) {
            $r = $rows[$category->id] ?? null;
            $category->setAttribute('spent_this_month', round((float) ($r->m_spent ?? 0), 2));
            $category->setAttribute('count_this_month', (int) ($r->m_cnt ?? 0));
            $category->setAttribute('all_time_spent', round((float) ($r->all_spent ?? 0), 2));
            $category->setAttribute('all_time_count', (int) ($r->all_cnt ?? 0));
        }
    }

    public function getStats(int $companyId): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $cats = $this->model->where('company_id', $companyId)->get(['id', 'status', 'monthly_budget']);
        $spend = Expense::query()
            ->where('company_id', $companyId)
            ->whereBetween('expense_date', [$monthStart, $monthEnd])
            ->groupBy('expense_category_id')
            ->selectRaw('expense_category_id as cid, SUM(amount) as spent, COUNT(*) as cnt')
            ->get()->keyBy('cid');

        $active = $cats->filter(fn ($c) => $c->status);
        $budgeted = $active->filter(fn ($c) => $c->monthly_budget !== null && (float) $c->monthly_budget > 0);
        $budgetTotal = round((float) $budgeted->sum('monthly_budget'), 2);
        $budgetSpent = (float) $budgeted->sum(fn ($c) => (float) ($spend[$c->id]->spent ?? 0));
        $over = $budgeted->filter(fn ($c) => (float) ($spend[$c->id]->spent ?? 0) > (float) $c->monthly_budget)->count();

        return [
            'activeCount' => $active->count(),
            'inactiveCount' => $cats->count() - $active->count(),
            'monthTotal' => round((float) $spend->sum('spent'), 2),
            'monthCount' => (int) $spend->sum('cnt'),
            'budgetTotal' => $budgetTotal,
            'budgetUsedPct' => $budgetTotal > 0 ? round($budgetSpent / $budgetTotal * 100, 1) : null,
            'overBudgetCount' => $over,
        ];
    }

    public function countExpenses(int $id): int
    {
        return Expense::where('expense_category_id', $id)->count();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?ExpenseCategory
    {
        return $this->model
            ->where('company_id', $companyId)
            ->find($id);
    }

    public function findByNameAndCompany(string $name, int $companyId): ?ExpenseCategory
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->first();
    }

    public function create(array $data): ExpenseCategory
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ExpenseCategory
    {
        $category = $this->model->findOrFail($id);
        $category->fill($data)->save();

        return $category;
    }

    public function delete(int $id): bool
    {
        $category = $this->model->findOrFail($id);

        return (bool) $category->delete();
    }
}
