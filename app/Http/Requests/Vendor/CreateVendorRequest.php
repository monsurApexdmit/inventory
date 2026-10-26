<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class CreateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'logo' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive,Blocked',
            'description' => 'nullable|string',
            'contactPerson' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'paymentTerms' => 'nullable|string|max:255',
            'openingBalance' => 'nullable|numeric|min:0',
            'tin' => 'nullable|string|max:255',
            'payTo' => 'nullable|string|max:255',
            'accountNumber' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'leadTimeDays' => 'nullable|integer|min:0|max:65535',
            'safetyDays' => 'nullable|integer|min:0|max:65535',
        ];
    }

    public function messages(): array
    {
        return [
            'openingBalance.min' => 'Opening balance cannot be negative.',
            'openingBalance.numeric' => 'Opening balance must be a number.',
            'leadTimeDays.min' => 'Lead time cannot be negative.',
            'leadTimeDays.integer' => 'Lead time must be a whole number of days.',
            'safetyDays.min' => 'Safety days cannot be negative.',
        ];
    }
}
