<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

/** POST /sells/{id}/courier */
class BookCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'courierAccountId' => 'nullable|integer|min:1',
            'codAmount' => 'nullable|numeric|min:0|max:10000000',
            'recipient' => 'nullable|array',
            'recipient.name' => 'nullable|string|max:100',
            'recipient.phone' => 'nullable|string|max:30',
            'recipient.address' => 'nullable|string|max:250',
            'pathao' => 'nullable|array',
            'pathao.storeId' => 'nullable|integer|min:1',
            'pathao.cityId' => 'nullable|integer|min:1',
            'pathao.zoneId' => 'nullable|integer|min:1',
            'pathao.areaId' => 'nullable|integer|min:1',
            'pathao.cityName' => 'nullable|string|max:120',
            'pathao.zoneName' => 'nullable|string|max:120',
            'pathao.areaName' => 'nullable|string|max:120',
        ], self::parcelRules());
    }

    public static function parcelRules(string $prefix = ''): array
    {
        return [
            "{$prefix}weightKg" => 'nullable|numeric|min:0.1|max:30',
            "{$prefix}deliveryType" => 'nullable|integer|in:12,48',
            "{$prefix}itemType" => 'nullable|integer|in:1,2',
            "{$prefix}itemDescription" => 'nullable|string|max:250',
            "{$prefix}note" => 'nullable|string|max:250',
        ];
    }
}
