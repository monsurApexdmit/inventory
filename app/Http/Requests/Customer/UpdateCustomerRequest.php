<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('businessName', 'required|string|max:255', function ($input) {
            return ($input->type ?? null) === 'business';
        });
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9\s\-()]{7,25}$/', function ($a, $v, $f) { if ($v !== null && !preg_match('/^\d{7,15}$/', preg_replace('/\D/', '', (string) $v))) { $f('The phone must have 7 to 15 digits.'); } }],
            'address' => 'sometimes|nullable|string',
            'city' => 'sometimes|nullable|string',
            'state' => 'sometimes|nullable|string',
            'zipCode' => 'sometimes|nullable|string',
            'district' => 'sometimes|nullable|string|max:255',
            'area' => 'sometimes|nullable|string|max:255',
            'country' => 'sometimes|nullable|string',
            'customerType' => 'sometimes|nullable|string|in:retail,wholesale,vip',
            'customerGroup' => 'sometimes|nullable|string|in:retail,wholesale,vip',
            'type' => 'sometimes|nullable|string|in:individual,business',
            'businessName' => 'sometimes|nullable|string|max:255',
            'licenceBin' => 'sometimes|nullable|string|max:100',
            'creditLimit' => 'sometimes|nullable|numeric|min:0',
            'openingBalance' => 'sometimes|nullable|numeric|min:0',
            'birthday' => 'sometimes|nullable|date|before_or_equal:today',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:50',
            'smsOptIn' => 'sometimes|nullable|boolean',
            'status' => 'sometimes|nullable|string|in:active,inactive',
            'notes' => 'sometimes|nullable|string',
            'storeCredit' => 'sometimes|nullable|numeric|min:0',
        ];
    }
}
