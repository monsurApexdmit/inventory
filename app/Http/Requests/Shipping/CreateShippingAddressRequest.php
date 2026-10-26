<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class CreateShippingAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerId' => 'nullable|integer|min:1',
            'fullName' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9][0-9\s\-()]{5,28}$/'],
            'email' => 'nullable|email|max:255',
            'addressLine1' => 'required|string|max:255',
            'addressLine2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postalCode' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9\- ]{2,9}$/'],
            'country' => 'nullable|string|max:100',
            'isDefault' => 'nullable|boolean',
            'addressType' => 'nullable|string|in:home,office,other',
        ];
    }
}
