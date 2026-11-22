<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

/** POST /sells/courier/bulk */
class BulkBookCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'sellIds' => 'required|array|min:1|max:100',
            'sellIds.*' => 'integer|min:1',
            'courierAccountId' => 'nullable|integer|min:1',
            'defaults' => 'nullable|array',
        ], BookCourierRequest::parcelRules('defaults.'));
    }
}
