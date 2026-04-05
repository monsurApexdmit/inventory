<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        // Only merge if at least one version of the field exists in the request
        $map = [
            'campaign_name'              => 'campaignName',
            'start_date'                 => 'startDate',
            'end_date'                   => 'endDate',
            'usage_limit'                => 'usageLimit',
            'usage_limit_per_user'       => 'usageLimitPerUser',
            'min_order_amount'           => 'minOrderAmount',
            'max_discount'               => 'maxDiscount',
            'applicable_to_categories'   => 'applicableToCategories',
            'applicable_to_products'     => 'applicableToProducts',
            'free_shipping'              => 'freeShipping',
            'auto_apply'                 => 'autoApply',
        ];

        foreach ($map as $snake => $camel) {
            if ($this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            } elseif ($this->has($snake)) {
                $merged[$snake] = $this->input($snake);
            }
            // If neither exists in the request, don't add to merged (field not being updated)
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'campaign_name' => 'sometimes|nullable|string|min:3|max:200',
            'code' => 'sometimes|nullable|string|min:3|max:50|alpha_num',
            'discount' => 'sometimes|nullable|numeric|min:0.01',
            'type' => 'sometimes|nullable|in:percentage,fixed,free_shipping',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date' => 'sometimes|nullable|date_format:Y-m-d',
            'status' => 'sometimes|nullable|boolean',
            'usage_limit' => 'sometimes|nullable|integer|min:1',
            'usage_limit_per_user' => 'sometimes|nullable|integer|min:1',
            'min_order_amount' => 'sometimes|nullable|numeric|min:0',
            'max_discount' => 'sometimes|nullable|numeric|min:0',
            'applicable_to_categories' => 'sometimes|nullable|string',
            'applicable_to_products' => 'sometimes|nullable|string',
            'free_shipping' => 'sometimes|nullable|boolean',
            'stackable' => 'sometimes|nullable|boolean',
            'auto_apply' => 'sometimes|nullable|boolean',
            'priority' => 'sometimes|nullable|integer|min:0',
        ];
    }
}
