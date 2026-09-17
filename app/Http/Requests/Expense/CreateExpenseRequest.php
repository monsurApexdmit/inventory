<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class CreateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expenseCategoryId' => 'nullable|integer|exists:expense_categories,id',
            'vendorId' => 'nullable|integer|exists:vendors,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expenseDate' => 'required|date',
            'paymentMethod' => 'required|string|max:255',
            'referenceNo' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'isRecurring' => 'nullable|boolean',
            'recurringFrequency' => 'nullable|string|in:weekly,monthly,yearly',
            'recurringEndDate' => 'nullable|date',
        ];
    }
}
