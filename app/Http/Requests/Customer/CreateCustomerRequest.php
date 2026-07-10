<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zipCode' => 'nullable|string',
            'country' => 'nullable|string',
            'customerType' => 'nullable|string|in:retail,wholesale',
            'status' => 'nullable|string|in:active,inactive',
            'notes' => 'nullable|string',
            'storeCredit' => 'nullable|numeric|min:0',
        ];
    }
}
