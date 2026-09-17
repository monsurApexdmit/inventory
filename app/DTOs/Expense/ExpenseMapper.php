<?php

namespace App\DTOs\Expense;

use App\DTOs\BaseMapper;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Expense model to ExpenseDTO
 */
class ExpenseMapper extends BaseMapper
{
    /**
     * Convert Expense model to DTO
     */
    public function toDTO(Model $model): ExpenseDTO
    {
        if (!$model instanceof Expense) {
            throw new \InvalidArgumentException('Model must be instance of Expense');
        }

        return new ExpenseDTO(
            id: $model->id,
            companyId: $model->company_id,
            expenseCategoryId: $model->expense_category_id,
            vendorId: $model->vendor_id,
            title: $model->title,
            amount: (float) $model->amount,
            expenseDate: $this->formatTimestamp($model->expense_date),
            paymentMethod: $model->payment_method,
            referenceNo: $model->reference_no,
            notes: $model->notes,
            receiptPath: $model->receipt_path,
            isRecurring: (bool) $model->is_recurring,
            recurringFrequency: $model->recurring_frequency,
            recurringEndDate: $this->formatTimestamp($model->recurring_end_date),
            uploadedBy: $model->uploaded_by,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            category: $model->relationLoaded('category') && $model->category ? [
                'id' => $model->category->id,
                'name' => $model->category->name,
            ] : null,
            vendor: $model->relationLoaded('vendor') && $model->vendor ? [
                'id' => $model->vendor->id,
                'name' => $model->vendor->name,
            ] : null,
        );
    }
}
