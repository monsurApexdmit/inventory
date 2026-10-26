<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class CreateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'monthlyBudget' => 'nullable|numeric|min:0',
            'type' => 'nullable|string|in:Operating cost,Cost of goods,Owner drawing,Tax / fees',
            'status' => 'nullable|boolean',
        ];
    }
}
