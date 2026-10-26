<?php

namespace App\DTOs\Customer;

use App\DTOs\BaseMapper;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Customer model to CustomerDTO
 */
class CustomerMapper extends BaseMapper
{
    /**
     * Convert Customer model to DTO
     */
    public function toDTO(Model $model): CustomerDTO
    {
        if (!$model instanceof Customer) {
            throw new \InvalidArgumentException('Model must be instance of Customer');
        }

        $group = $model->customer_group ?: ($model->customer_type ?: 'retail');
        $limit = (float) $model->credit_limit;
        // Credit used = unpaid sell dues (due_amount, sells only) + opening_balance.
        // opening_balance > 0 means the customer owes us; < 0 is an advance that offsets dues.
        // Floored at 0. dueAmount itself stays sells-only so list/overview stats don't double count.
        $used = max(0.0, round((float) ($model->getAttribute('due_amount') ?? 0) + (float) $model->opening_balance, 2));

        return new CustomerDTO(
            id: $model->id,
            companyId: $model->company_id,
            userId: $model->user_id,
            name: $model->name,
            email: $model->email,
            phone: $model->phone,
            address: $model->address,
            city: $model->city,
            state: $model->state,
            zipCode: $model->zip_code,
            country: $model->country,
            customerType: $group,
            status: $model->status,
            notes: $model->notes,
            storeCredit: (float) $model->store_credit,
            totalOrders: (int) ($model->getAttribute('total_orders') ?? 0),
            totalSpent: (float) ($model->getAttribute('total_spent') ?? 0),
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            dueAmount: (float) ($model->getAttribute('due_amount') ?? 0),
            lastOrderAt: $model->getAttribute('last_order_at') ? $this->formatTimestamp(\Carbon\Carbon::parse($model->getAttribute('last_order_at'))) : null,
            duplicatePhone: (bool) ($model->getAttribute('dup_phone') ?? false),
            type: $model->type ?: 'individual',
            businessName: $model->business_name,
            licenceBin: $model->licence_bin,
            customerGroup: $group,
            creditLimit: $limit,
            openingBalance: (float) $model->opening_balance,
            creditUsed: $used,
            creditUsedPercent: $limit > 0 ? round($used / $limit * 100, 1) : 0.0,
            birthday: $model->birthday?->format('Y-m-d'),
            tags: array_values($model->tags ?? []),
            smsOptIn: (bool) ($model->sms_opt_in ?? true),
            district: $model->district,
            area: $model->area,
            recentOrders: $model->getAttribute('recent_orders'),
        );
    }
}
