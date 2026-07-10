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
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'logo' => 'sometimes|nullable|string',
            'status' => 'sometimes|nullable|string|in:Active,Inactive,Blocked',
            'description' => 'sometimes|nullable|string',
            'totalPaid' => 'sometimes|nullable|numeric|min:0',
            'amountPayable' => 'sometimes|nullable|numeric|min:0',
        ];
    }
}
