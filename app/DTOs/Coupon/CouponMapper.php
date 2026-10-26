<?php

namespace App\DTOs\Coupon;

use App\DTOs\BaseMapper;
use App\Models\Coupon;
use App\Models\Sell;
use Illuminate\Database\Eloquent\Model;

class CouponMapper extends BaseMapper
{
    public function toDTO(Model $model): CouponDTO
    {
        if (!$model instanceof Coupon) {
            throw new \InvalidArgumentException('Model must be instance of Coupon');
        }

        $categoryIds = $this->parseIds($model->applicable_to_categories);
        $productIds = $this->parseIds($model->applicable_to_products);
        $appliesTo = !empty($categoryIds) ? 'categories' : (!empty($productIds) ? 'products' : 'all');

        $revenue = (float) Sell::where('coupon_id', $model->id)
            ->where('status', '!=', 'Cancelled')
            ->sum('amount');

        return new CouponDTO(
            id: $model->id,
            companyId: $model->company_id,
            campaignName: $model->campaign_name,
            code: $model->code,
            discount: (float) $model->discount,
            type: $model->type,
            startDate: $this->formatTimestamp($model->start_date),
            endDate: $this->formatTimestamp($model->end_date),
            status: $model->status,
            image: $model->image,
            uploadedBy: $model->uploaded_by,
            usageLimit: $model->usage_limit,
            usageLimitPerUser: $model->usage_limit_per_user,
            timesUsed: $model->times_used,
            minOrderAmount: (float) $model->min_order_amount,
            maxDiscount: $model->max_discount ? (float) $model->max_discount : null,
            applicableToCategories: $model->applicable_to_categories,
            applicableToProducts: $model->applicable_to_products,
            freeShipping: $model->free_shipping,
            stackable: $model->stackable,
            autoApply: $model->auto_apply,
            priority: $model->priority,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            appliesTo: $appliesTo,
            categoryIds: $categoryIds,
            productIds: $productIds,
            channelPos: (bool) ($model->channel_pos ?? true),
            channelOnline: (bool) ($model->channel_online ?? true),
            oncePerCustomer: (bool) ($model->once_per_customer ?? false),
            used: (int) $model->times_used,
            revenue: round($revenue, 2),
            phase: $model->phase(),
        );
    }

    private function parseIds(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $csv)), fn ($i) => $i > 0));
    }
}
