<?php

namespace App\DTOs\VendorReturn;

use App\DTOs\BaseMapper;
use App\Models\VendorReturnItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting VendorReturnItem model to VendorReturnItemDTO
 */
class VendorReturnItemMapper extends BaseMapper
{
    /**
     * Convert VendorReturnItem model to DTO
     */
    public function toDTO(Model $model): VendorReturnItemDTO
    {
        if (!$model instanceof VendorReturnItem) {
            throw new \InvalidArgumentException('Model must be instance of VendorReturnItem');
        }

        return new VendorReturnItemDTO(
            id: $model->id,
            returnId: $model->return_id,
            productId: $model->product_id,
            productName: $model->product_name,
            variantId: $model->variant_id,
            variantName: $model->variant_name,
            quantity: (int) $model->quantity,
            unitPrice: (float) $model->unit_price,
            totalPrice: (float) $model->total_price,
            unitCost: (float) $model->unit_cost,
            reason: $model->reason,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
