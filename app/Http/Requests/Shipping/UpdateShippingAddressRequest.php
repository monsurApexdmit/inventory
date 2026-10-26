<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerId' => 'sometimes|nullable|integer|min:1',
            'fullName' => 'sometimes|nullable|string|max:255',
            'phone' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^\+?[0-9][0-9\s\-()]{5,28}$/'],
            'email' => 'sometimes|nullable|email|max:255',
            'addressLine1' => 'sometimes|nullable|string|max:255',
            'addressLine2' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'postalCode' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9\- ]{2,9}$/'],
            'country' => 'sometimes|nullable|string|max:100',
            'isDefault' => 'sometimes|nullable|boolean',
            'addressType' => 'sometimes|nullable|string|in:home,office,other',
        ];
    }
}
