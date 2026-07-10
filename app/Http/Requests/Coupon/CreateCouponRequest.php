<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class CreateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'campaign_name' => $this->input('campaignName') ?? $this->input('campaign_name'),
            'start_date' => $this->input('startDate') ?? $this->input('start_date'),
            'end_date' => $this->input('endDate') ?? $this->input('end_date'),
            'usage_limit' => $this->input('usageLimit') ?? $this->input('usage_limit'),
            'usage_limit_per_user' => $this->input('usageLimitPerUser') ?? $this->input('usage_limit_per_user'),
            'min_order_amount' => $this->input('minOrderAmount') ?? $this->input('min_order_amount'),
            'max_discount' => $this->input('maxDiscount') ?? $this->input('max_discount'),
            'applicable_to_categories' => $this->input('applicableToCategories') ?? $this->input('applicable_to_categories'),
            'applicable_to_products' => $this->input('applicableToProducts') ?? $this->input('applicable_to_products'),
            'free_shipping' => $this->input('freeShipping') ?? $this->input('free_shipping'),
            'auto_apply' => $this->input('autoApply') ?? $this->input('auto_apply'),
        ]);
    }

    public function rules(): array
    {
        return [
            'campaign_name' => 'required|string|min:3|max:200',
            'code' => 'required|string|min:3|max:50|alpha_num',
            'discount' => 'required|numeric|min:0.01',
            'type' => 'required|in:percentage,fixed,free_shipping',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'status' => 'nullable|boolean',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'applicable_to_categories' => 'nullable|string',
            'applicable_to_products' => 'nullable|string',
            'free_shipping' => 'nullable|boolean',
            'stackable' => 'nullable|boolean',
            'auto_apply' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
        ];
    }
}
