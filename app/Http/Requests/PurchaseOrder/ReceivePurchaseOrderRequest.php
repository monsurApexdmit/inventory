<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locationId'                => 'nullable|integer',
            'items'                     => 'required|array|min:1',
            'items.*.itemId'            => 'required|integer',
            'items.*.quantityReceiving' => 'required|integer|min:1',
        ];
    }
}
