<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseMapper;
use App\Models\BillingContact;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting BillingContact model to BillingContactDTO
 */
class BillingContactMapper extends BaseMapper
{
    /**
     * Convert BillingContact model to DTO
     */
    public function toDTO(Model $model): BillingContactDTO
    {
        if (!$model instanceof BillingContact) {
            throw new \InvalidArgumentException('Model must be instance of BillingContact');
        }

        return new BillingContactDTO(
            id: $model->id,
            companyId: $model->company_id,
            email: $model->email,
            phone: $model->phone,
            address: $model->address,
            city: $model->city,
            state: $model->state,
            zipCode: $model->zip_code,
            country: $model->country,
            taxId: $model->tax_id,
            taxIdType: $model->tax_id_type,
            isDefault: (bool) $model->is_default,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
