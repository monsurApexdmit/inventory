<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-()]{7,25}$/', function ($a, $v, $f) { if ($v !== null && !preg_match('/^\d{7,15}$/', preg_replace('/\D/', '', (string) $v))) { $f('The phone must have 7 to 15 digits.'); } }],
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'zipCode' => 'nullable|string',
            'district' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:255',
            'country' => 'nullable|string',
            'customerType' => 'nullable|string|in:retail,wholesale,vip',
            'customerGroup' => 'nullable|string|in:retail,wholesale,vip',
            'type' => 'nullable|string|in:individual,business',
            'businessName' => 'nullable|string|max:255',
            'licenceBin' => 'nullable|string|max:100',
            'creditLimit' => 'nullable|numeric|min:0',
            'openingBalance' => 'nullable|numeric|min:0',
            'birthday' => 'nullable|date|before_or_equal:today',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'smsOptIn' => 'nullable|boolean',
            'status' => 'nullable|string|in:active,inactive',
            'notes' => 'nullable|string',
            'storeCredit' => 'nullable|numeric|min:0',
        ];
    }
}
