<?php

namespace App\DTOs\CustomerReturn;

use App\DTOs\BaseMapper;
use App\Models\CustomerReturnItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting CustomerReturnItem model to CustomerReturnItemDTO
 */
class CustomerReturnItemMapper extends BaseMapper
{
    /**
     * Convert CustomerReturnItem model to DTO
     */
    public function toDTO(Model $model): CustomerReturnItemDTO
    {
        if (!$model instanceof CustomerReturnItem) {
            throw new \InvalidArgumentException('Model must be instance of CustomerReturnItem');
        }

        return new CustomerReturnItemDTO(
            id: $model->id,
            returnId: $model->return_id,
            productId: $model->product_id,
            productName: $model->product_name,
            variantId: $model->variant_id,
            variantName: $model->variant_name,
            quantity: (int) $model->quantity,
            price: (float) $model->price,
            reason: $model->reason,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
