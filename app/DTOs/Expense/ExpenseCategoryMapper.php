<?php

namespace App\DTOs\Expense;

use App\DTOs\BaseMapper;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting ExpenseCategory model to ExpenseCategoryDTO
 */
class ExpenseCategoryMapper extends BaseMapper
{
    /**
     * Convert ExpenseCategory model to DTO
     */
    public function toDTO(Model $model): ExpenseCategoryDTO
    {
        if (!$model instanceof ExpenseCategory) {
            throw new \InvalidArgumentException('Model must be instance of ExpenseCategory');
        }

        return new ExpenseCategoryDTO(
            id: $model->id,
            companyId: $model->company_id,
            name: $model->name,
            description: $model->description,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            color: $model->color,
            monthlyBudget: $model->monthly_budget !== null ? (float) $model->monthly_budget : null,
            type: $model->type ?: 'Operating cost',
            status: (bool) ($model->status ?? true),
            system: $model->isSystem(),
            usage: $model->getAttribute('spent_this_month') !== null ? [
                'spentThisMonth' => (float) $model->getAttribute('spent_this_month'),
                'countThisMonth' => (int) $model->getAttribute('count_this_month'),
                'allTimeSpent' => (float) $model->getAttribute('all_time_spent'),
                'allTimeCount' => (int) $model->getAttribute('all_time_count'),
            ] : null,
        );
    }
}
