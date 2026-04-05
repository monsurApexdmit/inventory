<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'companyName' => 'nullable|string|max:255',
            'taxId' => 'nullable|string|max:255',
            'taxIdType' => 'nullable|string|max:255',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:255',
            'language' => 'nullable|string|size:2',
        ];
    }
}
