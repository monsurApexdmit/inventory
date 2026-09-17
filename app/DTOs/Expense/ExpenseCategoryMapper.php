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
        );
    }
}
