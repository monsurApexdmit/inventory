<?php

namespace App\Http\Requests\Location;

use Illuminate\Foundation\Http\FormRequest;

class CreateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'contactPerson' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:Warehouse,Shop,Pickup point',
            'district' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'manager' => 'nullable|string|max:150',
            'sellsInPos' => 'nullable|boolean',
            'shipsOnline' => 'nullable|boolean',
            'isPickupPoint' => 'nullable|boolean',
            'isDefault' => 'nullable|boolean',
        ];
    }
}
