<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

class AddTrackingEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|string',
            'location' => 'nullable|string',
            'description' => 'nullable|string',
            'eventTime' => 'nullable|date',
        ];
    }
}
