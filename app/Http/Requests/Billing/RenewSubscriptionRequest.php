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
            'billingPeriod' => 'nullable|in:monthly,yearly',
            'paymentMethod' => 'nullable|in:card,bkash,bank',
            'billingEmail' => 'nullable|email|max:255',
        ];
    }
}
