<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use App\Repositories\Contracts\IExpenseRepository;

class ExpenseRepository implements IExpenseRepository
{
    public function __construct(private readonly Expense $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'vendor']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('reference_no', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['category_id'])) {
            $query->where('expense_category_id', $filters['category_id']);
        }

        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (isset($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('expense_date', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('expense_date', '<=', $filters['end_date']);
        }

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->orderByDesc('expense_date')->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Expense
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['category', 'vendor'])
            ->find($id);
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
