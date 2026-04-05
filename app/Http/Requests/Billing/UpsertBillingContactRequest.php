<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class UpsertBillingContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zipCode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
            'taxId' => 'nullable|string|max:255',
            'taxIdType' => 'nullable|string|max:255',
            'isDefault' => 'nullable|boolean',
        ];
    }
}
