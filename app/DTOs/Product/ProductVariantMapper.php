<?php

namespace App\DTOs\Product;

use App\DTOs\BaseMapper;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting ProductVariant model to ProductVariantDTO
 */
class ProductVariantMapper extends BaseMapper
{
    /**
     * Convert ProductVariant model to DTO
     */
    public function toDTO(Model $model): ProductVariantDTO
    {
        if (!$model instanceof ProductVariant) {
            throw new \InvalidArgumentException('Model must be instance of ProductVariant');
        }

        // Format inventory data if available
        $inventory = null;
        if ($model->relationLoaded('inventory') && $model->inventory) {
            $inventory = $model->inventory->map(fn($inv) => [
                'id' => $inv->id,
                'variantId' => $inv->variant_id,
                'locationId' => $inv->location_id,
                'quantity' => $inv->quantity,
            ])->toArray();
        }

        return new ProductVariantDTO(
            id: $model->id,
            productId: $model->product_id,
            name: $model->name,
            attributes: $model->attributes,
            price: (float) $model->price,
            salePrice: (float) $model->sale_price,
            costPrice: (float) $model->cost_price,
            offerPrice: $model->offer_price ? (float) $model->offer_price : null,
            offerType: $model->offer_type,
            stock: (int) $model->stock,
            sku: $model->sku,
            barcode: $model->barcode,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            inventory: $inventory,
        );
    }
}
