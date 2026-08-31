<?php

namespace App\Http\Requests\Brand;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brandName' => 'sometimes|required|string|max:150',
            'logo'      => 'sometimes|nullable|string|max:255',
            'status'    => 'sometimes|nullable|boolean',
        ];
    }
}
