<?php

namespace App\Http\Requests\Unit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unitName' => 'sometimes|required|string|max:100',
            'symbol'   => 'nullable|string|max:20',
            'status'   => 'nullable|boolean',
        ];
    }
}
