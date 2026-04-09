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
            'taxId' => 'nullable|string|max:255',
            'taxIdType' => 'nullable|string|max:255',
            'taxRate' => 'nullable|numeric|min:0|max:100',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:10',
            // International/Regional Settings
            'currencySymbolPosition' => 'nullable|string|in:before,after',
            'currencyDecimalSeparator' => ['nullable', 'string', 'in:.,'],
            'currencyThousandsSeparator' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (!in_array($value, [',', '.', ' ', ''])) {
                    $fail('The ' . $attribute . ' must be comma, period, space or empty.');
                }
            }],
            'currencyDecimalPlaces' => 'nullable|integer|in:0,1,2',
            'weightUnit' => 'nullable|string|in:kg,lb,g,oz',
            'dimensionUnit' => 'nullable|string|in:cm,in,mm',
            'dateFormat' => 'nullable|string|in:MM/DD/YYYY,DD/MM/YYYY,YYYY-MM-DD',
            'timeFormat' => 'nullable|string|in:12h,24h',
        ];
    }

    public function messages(): array
    {
        return [
            'currencyDecimalSeparator.regex' => 'Decimal separator must be . or ,',
            'currencyThousandsSeparator.regex' => 'Thousands separator must be , . space or empty',
            'currencySymbolPosition.in' => 'Currency symbol position must be before or after',
            'currencyDecimalPlaces.in' => 'Decimal places must be 0, 1, or 2',
            'weightUnit.in' => 'Weight unit must be kg, lb, g, or oz',
            'dimensionUnit.in' => 'Dimension unit must be cm, in, or mm',
            'dateFormat.in' => 'Date format must be MM/DD/YYYY, DD/MM/YYYY, or YYYY-MM-DD',
            'timeFormat.in' => 'Time format must be 12h or 24h',
        ];
    }
}
