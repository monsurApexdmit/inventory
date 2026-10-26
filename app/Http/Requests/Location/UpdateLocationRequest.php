<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'contactPerson' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|nullable|string|in:Warehouse,Shop,Pickup point',
            'district' => 'sometimes|nullable|string|max:100',
            'phone' => 'sometimes|nullable|string|max:50',
            'manager' => 'sometimes|nullable|string|max:150',
            'sellsInPos' => 'sometimes|nullable|boolean',
            'shipsOnline' => 'sometimes|nullable|boolean',
            'isPickupPoint' => 'sometimes|nullable|boolean',
            'isDefault' => 'sometimes|nullable|boolean',
        ];
    }
}
