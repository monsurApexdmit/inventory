<?php

namespace App\DTOs\Vendor;

use App\DTOs\BaseMapper;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Vendor model to VendorDTO
 */
class VendorMapper extends BaseMapper
{
    /**
     * Convert Vendor model to DTO
     */
    public function toDTO(Model $model): VendorDTO
    {
        if (!$model instanceof Vendor) {
            throw new \InvalidArgumentException('Model must be instance of Vendor');
        }

        return new VendorDTO(
            id: $model->id,
            companyId: $model->company_id,
            userId: $model->user_id,
            name: $model->name,
            email: $model->email,
            phone: $model->phone,
            address: $model->address,
            logo: $model->logo,
            uploadedBy: $model->uploaded_by,
            status: $model->status,
            description: $model->description,
            totalPaid: (float) $model->total_paid,
            amountPayable: (float) $model->amount_payable,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
