<?php

namespace App\Http\Requests\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
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
            'address' => 'sometimes|nullable|string|max:255',
            'logo' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:Active,Inactive,Blocked',
            'description' => 'sometimes|nullable|string',
            'contactPerson' => 'sometimes|nullable|string|max:255',
            'district' => 'sometimes|nullable|string|max:255',
            'country' => 'sometimes|nullable|string|max:255',
            'paymentTerms' => 'sometimes|nullable|string|max:255',
            'openingBalance' => 'sometimes|nullable|numeric|min:0',
            'tin' => 'sometimes|nullable|string|max:255',
            'payTo' => 'sometimes|nullable|string|max:255',
            'accountNumber' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string',
            'leadTimeDays' => 'sometimes|nullable|integer|min:0|max:65535',
            'safetyDays' => 'sometimes|nullable|integer|min:0|max:65535',
            'totalPaid' => 'sometimes|nullable|numeric|min:0',
            'amountPayable' => 'sometimes|nullable|numeric|min:0',
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
