<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expenseCategoryId' => 'sometimes|nullable|integer|exists:expense_categories,id',
            'vendorId' => 'sometimes|nullable|integer|exists:vendors,id',
            'title' => 'sometimes|nullable|string|max:255',
            'amount' => 'sometimes|nullable|numeric|min:0',
            'expenseDate' => 'sometimes|nullable|date',
            'paymentMethod' => 'sometimes|nullable|string|max:255',
            'referenceNo' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string',
            'receipt' => 'sometimes|nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'isRecurring' => 'sometimes|nullable|boolean',
            'recurringFrequency' => 'sometimes|nullable|string|in:weekly,monthly,yearly',
            'recurringEndDate' => 'sometimes|nullable|date',
        ];
    }
}
