<?php

namespace App\DTOs\VendorReturn;

use App\DTOs\BaseMapper;
use App\Models\VendorReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting VendorReturn model to VendorReturnDTO
 */
class VendorReturnMapper extends BaseMapper
{
    /**
     * Convert VendorReturn model to DTO
     */
    public function toDTO(Model $model): VendorReturnDTO
    {
        if (!$model instanceof VendorReturn) {
            throw new \InvalidArgumentException('Model must be instance of VendorReturn');
        }

        return new VendorReturnDTO(
            id: $model->id,
            companyId: $model->company_id,
            vendorId: $model->vendor_id,
            returnNumber: $model->return_number,
            vendorName: $model->vendor_name,
            totalAmount: (float) $model->total_amount,
            status: $model->status,
            completedDate: $model->completed_date ? $this->formatTimestamp($model->completed_date) : null,
            creditType: $model->credit_type,
            notes: $model->notes,
            returnDate: $this->formatTimestamp($model->return_date),
            createdBy: $model->created_by,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            items: $model->relationLoaded('items') ? $this->formatItems($model->items) : null,
        );
    }

    /**
     * Format return items
     */
    private function formatItems($items): ?array
    {
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        $itemMapper = new VendorReturnItemMapper();
        return $itemMapper->toArrayCollection($items);
    }
}
