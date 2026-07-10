<?php

namespace App\Http\Requests\VendorReturn;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendorId' => 'sometimes|nullable|integer|min:1',
            'vendorName' => 'sometimes|nullable|string|max:255',
            'totalAmount' => 'sometimes|nullable|numeric|min:0',
            'creditType' => 'sometimes|nullable|string|in:refund,credit_note,replacement',
            'status' => 'sometimes|nullable|string|in:pending,shipped,received_by_vendor,completed',
            'returnDate' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
