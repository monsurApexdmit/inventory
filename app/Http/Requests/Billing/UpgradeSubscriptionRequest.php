<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class UpgradeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planId' => 'required|integer|exists:subscription_plans,id',
            'billingPeriod' => 'nullable|in:monthly,yearly',
            'paymentMethod' => 'nullable|in:card,bkash,bank',
            'billingEmail' => 'nullable|email|max:255',
        ];
    }
}
