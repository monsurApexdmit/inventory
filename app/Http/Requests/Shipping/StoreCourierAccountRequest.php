<?php

namespace App\Http\Requests\Shipping;

use Illuminate\Foundation\Http\FormRequest;

/** Connect a courier account. Provider-specific required credentials are checked in CourierAccountService. */
class StoreCourierAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'provider' => 'required|string|max:100',
        ], self::sharedRules());
    }

    public static function sharedRules(): array
    {
        return [
            'accountId' => 'nullable|string|max:150',
            'apiKey' => 'nullable|string|max:500',
            'isActive' => 'boolean',
            'isDefault' => 'boolean',
            'environment' => 'nullable|string|in:live,sandbox',
            'credentials' => 'nullable|array',
            'credentials.apiKey' => 'nullable|string|max:500',
            'credentials.secretKey' => 'nullable|string|max:500',
            'credentials.clientId' => 'nullable|string|max:255',
            'credentials.clientSecret' => 'nullable|string|max:500',
            'credentials.username' => 'nullable|string|max:255',
            'credentials.password' => 'nullable|string|max:255',
            'credentials.storeId' => 'nullable|max:50',
            'settings' => 'nullable|array',
            'settings.deliveryType' => 'nullable|integer|in:12,48',
            'settings.itemType' => 'nullable|integer|in:1,2',
            'settings.weightKg' => 'nullable|numeric|min:0.1|max:30',
            'settings.note' => 'nullable|string|max:250',
            'settings.itemDescription' => 'nullable|string|max:250',
        ];
    }
}
