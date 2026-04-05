<?php

namespace App\DTOs\StockTransfer;

use App\DTOs\BaseMapper;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting StockTransfer model to StockTransferDTO
 */
class StockTransferMapper extends BaseMapper
{
    /**
     * Convert StockTransfer model to DTO
     */
    public function toDTO(Model $model): StockTransferDTO
    {
        if (!$model instanceof StockTransfer) {
            throw new \InvalidArgumentException('Model must be instance of StockTransfer');
        }

        return new StockTransferDTO(
            id: $model->id,
            companyId: $model->company_id,
            productId: $model->product_id,
            variantId: $model->variant_id,
            fromLocationId: $model->from_location_id,
            toLocationId: $model->to_location_id,
            quantity: (int) $model->quantity,
            status: $model->status,
            notes: $model->notes,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            product: $this->formatRelation($model->product),
            variant: $this->formatRelation($model->variant),
            fromLocation: $this->formatRelation($model->fromLocation),
            toLocation: $this->formatRelation($model->toLocation),
            company: $this->formatRelation($model->company),
        );
    }
}
