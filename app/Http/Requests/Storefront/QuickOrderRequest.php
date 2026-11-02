<?php

namespace App\Http\Requests\Storefront;

use App\Support\BdPhone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** POST /api/store/quick-order (camelCase). See AD_ORDERS_API_CONTRACT.md §4.3. */
class QuickOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'productId' => ['required', 'integer', 'min:1'],
            'variantId' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30', function ($attr, $value, $fail) {
                if (!BdPhone::normalize(is_string($value) ? $value : null)) {
                    $fail('Enter a valid Bangladeshi mobile number, e.g. 01712345678.');
                }
            }],
            'address' => ['required', 'string', 'min:5', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'shippingMethodId' => ['required', 'integer', 'min:1'],
            'paymentMethod' => ['required', 'string', 'max:50'],
            'transactionId' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable'],
            'attribution' => ['nullable', 'array'],
            'attribution.*' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'productId.required' => 'Choose a product.',
            'productId.integer' => 'Choose a product.',
            'productId.min' => 'Choose a product.',
            'variantId.integer' => 'That option is not available for this product.',
            'variantId.min' => 'That option is not available for this product.',
            'quantity.required' => 'Quantity must be between 1 and 50.',
            'quantity.integer' => 'Quantity must be between 1 and 50.',
            'quantity.min' => 'Quantity must be between 1 and 50.',
            'quantity.max' => 'Quantity must be between 1 and 50.',
            'name.required' => 'Enter your name.',
            'name.min' => 'Enter your name.',
            'name.max' => 'The name may not be longer than 120 characters.',
            'phone.required' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.',
            'phone.string' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.',
            'phone.max' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.',
            'address.required' => 'Enter your full delivery address.',
            'address.min' => 'Enter your full delivery address.',
            'address.max' => 'The address may not be longer than 255 characters.',
            'district.max' => 'The district may not be longer than 100 characters.',
            'area.max' => 'The area may not be longer than 100 characters.',
            'shippingMethodId.required' => 'Choose a delivery option',
            'shippingMethodId.integer' => 'Choose a delivery option',
            'shippingMethodId.min' => 'Choose a delivery option',
            'paymentMethod.required' => 'Choose a payment method.',
            'transactionId.max' => 'The transaction ID may not be longer than 100 characters.',
            'note.max' => 'The note may not be longer than 500 characters.',
            'attribution.array' => 'Attribution must be an object.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $errors->first() ?: 'Validation failed.',
            'errors' => $errors->toArray(),
        ], 422));
    }
}
