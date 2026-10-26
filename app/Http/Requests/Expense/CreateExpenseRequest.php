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
            'recurringFrequency' => ['nullable', 'string', function ($attr, $value, $fail) {
                if ($value !== null && \App\Models\Expense::normalizeFrequency($value) === null) {
                    $fail('The recurring frequency must be one of Week, Month, Quarter, Year.');
                }
            }],
            'nextDueDate' => 'nullable|date',
            'locationId' => 'nullable|integer|exists:locations,id',
            'paidTo' => 'nullable|string|max:255',
            'recurringEndDate' => 'nullable|date',
        ];
    }
}
