<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string',
            'color' => 'sometimes|nullable|string|max:7',
            'monthlyBudget' => 'sometimes|nullable|numeric|min:0',
            'type' => 'sometimes|nullable|string|in:Operating cost,Cost of goods,Owner drawing,Tax / fees',
            'status' => 'sometimes|nullable|boolean',
        ];
    }
}
