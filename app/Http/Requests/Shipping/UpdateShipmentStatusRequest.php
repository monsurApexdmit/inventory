<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => 'nullable|string',
            'rider' => 'nullable|string|max:100',
            'status' => 'required|string|in:pending,picked_up,in_transit,out_for_delivery,delivered,failed,returned',
            'location' => 'nullable|string',
            'description' => 'nullable|string',
        ];
    }
}
