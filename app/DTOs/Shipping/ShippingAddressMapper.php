<?php

namespace App\DTOs\Shipping;

use App\DTOs\BaseMapper;
use App\Models\ShippingAddress;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting ShippingAddress model to ShippingAddressDTO
 */
class ShippingAddressMapper extends BaseMapper
{
    /**
     * Convert ShippingAddress model to DTO
     */
    public function toDTO(Model $model): ShippingAddressDTO
    {
        if (!$model instanceof ShippingAddress) {
            throw new \InvalidArgumentException('Model must be instance of ShippingAddress');
        }

        return new ShippingAddressDTO(
            id: $model->id,
            companyId: $model->company_id,
            customerId: $model->customer_id,
            fullName: $model->full_name,
            phone: $model->phone,
            email: $model->email,
            addressLine1: $model->address_line1,
            addressLine2: $model->address_line2,
            city: $model->city,
            state: $model->state,
            postalCode: $model->postal_code,
            country: $model->country,
            isDefault: (bool) $model->is_default,
            addressType: $model->address_type,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
