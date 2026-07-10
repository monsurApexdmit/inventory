<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class RenewSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subscriptionId' => 'required|integer|exists:subscriptions,id',
            'autoRenew' => 'nullable|boolean',
        ];
    }
}
