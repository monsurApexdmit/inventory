<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bank_account' => $this->bankAccount,
            'payment_method' => $this->paymentMethod,
            'joining_date' => $this->joiningDate,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:staff,email',
            'contact' => 'nullable|string|max:255',
            'joining_date' => 'nullable|string|max:255',
            'role' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:Active,Inactive',
            'published' => 'nullable|boolean',
            'avatar' => 'nullable|string',
            'password' => 'nullable|string|min:8',
            'salary' => 'nullable|numeric|min:0',
            'bank_account' => 'nullable|string|max:255',
            'payment_method' => 'nullable|string|in:Bank Transfer,Cash,Check',
        ];
    }
}
