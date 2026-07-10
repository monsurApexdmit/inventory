<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegionalSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Regional Settings - all optional for partial updates
            'language' => ['sometimes', 'string', 'in:en,es,fr,de,it,pt,ru,ar,zh,zh-TW,ja,ko,hi,bn,tr,nl,sv,pl,id,vi,th'],
            'currency' => ['sometimes', 'string', 'in:USD,EUR,GBP,INR,AUD,CAD,JPY,CNY,BDT,PKR,NGN,KES,ZAR,BRL,MXN,SGD,HKD,SEK,NOK,DKK,CHF,NZD,AED,SAR,MYR,PHP,THB,IDR,VND,KRW,TRY,EGP,GHS'],
            'timezone' => ['sometimes', 'string', 'in:UTC,America/New_York,America/Chicago,America/Denver,America/Los_Angeles,America/Anchorage,America/Toronto,America/Mexico_City,America/Sao_Paulo,Europe/London,Europe/Paris,Europe/Berlin,Europe/Moscow,Asia/Dubai,Asia/Kolkata,Asia/Dhaka,Asia/Bangkok,Asia/Singapore,Asia/Hong_Kong,Asia/Tokyo,Asia/Shanghai,Asia/Seoul,Australia/Sydney,Australia/Melbourne,Australia/Perth,Pacific/Auckland,Pacific/Fiji,Africa/Cairo,Africa/Lagos,Africa/Nairobi,Africa/Johannesburg'],
            'currencySymbolPosition' => ['sometimes', 'string', 'in:before,after'],
            'currencyDecimalSeparator' => ['sometimes', 'string', 'in:.,'],
            'currencyThousandsSeparator' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                if (!in_array($value, [',', '.', ' ', ''])) {
                    $fail('The ' . $attribute . ' must be comma, period, space or empty.');
                }
            }],
            'currencyDecimalPlaces' => ['sometimes', 'integer', 'in:0,1,2'],
            'weightUnit' => ['sometimes', 'string', 'in:kg,lb,g,oz'],
            'dimensionUnit' => ['sometimes', 'string', 'in:cm,in,mm'],
            'dateFormat' => ['sometimes', 'string', 'in:MM/DD/YYYY,DD/MM/YYYY,YYYY-MM-DD'],
            'timeFormat' => ['sometimes', 'string', 'in:12h,24h'],
        ];
    }

    public function messages(): array
    {
        return [
            'language.in' => 'The selected language is not supported.',
            'currency.in' => 'The selected currency is not supported.',
            'timezone.in' => 'The selected timezone is not valid.',
            'currencySymbolPosition.in' => 'Currency symbol position must be before or after.',
            'currencyDecimalSeparator.in' => 'Decimal separator must be . or ,',
            'currencyThousandsSeparator.in' => 'Thousands separator must be , . or space.',
            'currencyDecimalPlaces.in' => 'Decimal places must be 0, 1, or 2.',
            'weightUnit.in' => 'Weight unit must be kg, lb, g, or oz.',
            'dimensionUnit.in' => 'Dimension unit must be cm, in, or mm.',
            'dateFormat.in' => 'Date format must be MM/DD/YYYY, DD/MM/YYYY, or YYYY-MM-DD.',
            'timeFormat.in' => 'Time format must be 12h or 24h.',
        ];
    }
}
