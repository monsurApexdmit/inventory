<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseMapper;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Subscription model to SubscriptionDTO
 */
class SubscriptionMapper extends BaseMapper
{
    /**
     * Convert Subscription model to DTO
     */
    public function toDTO(Model $model): SubscriptionDTO
    {
        if (!$model instanceof Subscription) {
            throw new \InvalidArgumentException('Model must be instance of Subscription');
        }

        $period = $model->billing_period ?: ($model->plan?->billing_period ?? 'monthly');
        $price = (int) $model->plan?->price;
        $end = $model->current_period_end;

        return new SubscriptionDTO(
            id: $model->id,
            companyId: $model->company_id,
            planId: $model->plan_id,
            planName: $model->plan?->name,
            price: (int) $model->plan?->price,
            billingPeriod: $period,
            status: $model->status,
            currentPeriodStart: $model->current_period_start ? $this->formatTimestamp($model->current_period_start) : null,
            currentPeriodEnd: $model->current_period_end ? $this->formatTimestamp($model->current_period_end) : null,
            nextBillingDate: $model->next_billing_date ? $this->formatTimestamp($model->next_billing_date) : null,
            autoRenew: (bool) $model->auto_renew,
            stripeSubscriptionId: $model->stripe_subscription_id,
            cancelledAt: $model->cancelled_at ? $this->formatTimestamp($model->cancelled_at) : null,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            cancelReason: $model->cancel_reason,
            isTrial: $price === 0,
            periodPrice: $period === 'yearly' ? $price * 10 : $price,
            daysRemaining: $end ? max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400)) : 0,
        );
    }
}
