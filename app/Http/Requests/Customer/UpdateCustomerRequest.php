<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'address' => 'sometimes|nullable|string',
            'city' => 'sometimes|nullable|string',
            'state' => 'sometimes|nullable|string',
            'zipCode' => 'sometimes|nullable|string',
            'country' => 'sometimes|nullable|string',
            'customerType' => 'sometimes|nullable|string|in:retail,wholesale',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'notes' => 'sometimes|nullable|string',
            'storeCredit' => 'sometimes|nullable|numeric|min:0',
        ];
    }
}
