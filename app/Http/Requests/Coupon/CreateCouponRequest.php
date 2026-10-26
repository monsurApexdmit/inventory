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
        $m = [
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
        ];

        // appliesTo + categoryIds/productIds -> applicable_to_* (comma separated ids)
        $csv = fn ($v) => is_array($v) ? (count($v) ? implode(',', array_map('intval', $v)) : null) : (($v === '' || $v === null) ? null : (string) $v);
        $applies = $this->input('appliesTo');
        if ($applies !== null) {
            $m['applies_to'] = $applies;
            $m['applicable_to_categories'] = $applies === 'categories' ? $csv($this->input('categoryIds')) : null;
            $m['applicable_to_products'] = $applies === 'products' ? $csv($this->input('productIds')) : null;
        } else {
            if ($this->has('categoryIds')) $m['applicable_to_categories'] = $csv($this->input('categoryIds'));
            if ($this->has('productIds')) $m['applicable_to_products'] = $csv($this->input('productIds'));
        }
        foreach (['channelPos' => 'channel_pos', 'channelOnline' => 'channel_online', 'oncePerCustomer' => 'once_per_customer'] as $camel => $snake) {
            if ($this->has($camel)) $m[$snake] = $this->input($camel);
        }
        $this->merge($m);
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers, dashes and underscores only',
            'usage_limit.integer' => 'Total uses must be a whole number',
            'usage_limit_per_user.integer' => 'Uses per customer must be a whole number',
        ];
    }

    public function rules(): array
    {
        return [
            'campaign_name' => 'required|string|min:3|max:200',
            'code' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount' => 'required|numeric|min:0.01|max:100000000',
            'type' => 'required|in:percentage,fixed,free_shipping',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'status' => 'nullable|boolean',
            'usage_limit' => 'nullable|integer|min:1|max:100000000',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'applicable_to_categories' => 'nullable|string',
            'applicable_to_products' => 'nullable|string',
            'free_shipping' => 'nullable|boolean',
            'stackable' => 'nullable|boolean',
            'auto_apply' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
            'applies_to' => 'nullable|in:all,categories,products',
            'channel_pos' => 'nullable|boolean',
            'channel_online' => 'nullable|boolean',
            'once_per_customer' => 'nullable|boolean',
        ];
    }
}
