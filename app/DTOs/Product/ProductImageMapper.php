<?php

namespace App\DTOs\Product;

use App\DTOs\BaseMapper;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting ProductImage model to ProductImageDTO
 */
class ProductImageMapper extends BaseMapper
{
    /**
     * Convert ProductImage model to DTO
     */
    public function toDTO(Model $model): ProductImageDTO
    {
        if (!$model instanceof ProductImage) {
            throw new \InvalidArgumentException('Model must be instance of ProductImage');
        }

        return new ProductImageDTO(
            id: $model->id,
            productId: $model->product_id,
            path: $model->path,
            position: (int) $model->position,
            isPrimary: (bool) $model->is_primary,
            createdAt: $this->formatTimestamp($model->created_at),
        );
    }
}
