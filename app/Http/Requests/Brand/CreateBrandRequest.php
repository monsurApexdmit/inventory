<?php

namespace App\Http\Requests\Brand;

use Illuminate\Foundation\Http\FormRequest;

class CreateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brandName' => 'required|string|max:150',
            'logo'      => 'nullable|string|max:255|url:http,https',
            'website'   => 'nullable|string|max:255|url:http,https',
            'description' => 'nullable|string|max:5000',
            'status'    => 'nullable|boolean',
        ];
    }
}
