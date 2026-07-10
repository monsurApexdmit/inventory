<?php

namespace App\Http\Requests\CustomerReturn;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|nullable|string|in:pending,approved,rejected,completed',
            'notes' => 'sometimes|nullable|string',
            'processedBy' => 'sometimes|nullable|string|max:255',
        ];
    }
}
