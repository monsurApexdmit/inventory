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
            customerType: $model->customer_type,
            status: $model->status,
            notes: $model->notes,
            storeCredit: (float) $model->store_credit,
            totalOrders: (int) ($model->getAttribute('total_orders') ?? 0),
            totalSpent: (float) ($model->getAttribute('total_spent') ?? 0),
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
