<?php

namespace App\DTOs\Product;

use App\DTOs\BaseMapper;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Product model to ProductDTO
 */
class ProductMapper extends BaseMapper
{
    /**
     * Convert Product model to DTO
     */
    public function toDTO(Model $model): ProductDTO
    {
        if (!$model instanceof Product) {
            throw new \InvalidArgumentException('Model must be instance of Product');
        }

        // Get image: use $model->image, or fall back to primary image from images relation
        $image = $model->image;
        if (!$image && $model->relationLoaded('images') && $model->images && $model->images->count() > 0) {
            $primaryImage = $model->images->firstWhere('is_primary', true);
            $image = $primaryImage?->path ?? $model->images->first()?->path;
        }

        return new ProductDTO(
            id: $model->id,
            companyId: $model->company_id,
            categoryId: $model->category_id,
            vendorId: $model->vendor_id,
            locationId: $model->location_id,
            name: $model->name,
            description: $model->description,
            price: (float) $model->price,
            salePrice: (float) $model->sale_price,
            costPrice: (float) $model->cost_price,
            stock: (int) $model->stock,
            sku: $model->sku,
            barcode: $model->barcode,
            published: (bool) $model->published,
            receiptNumber: $model->receipt_number,
            image: $image,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            variants: $model->relationLoaded('variants') ? $this->formatVariants($model->variants) : null,
            images: $model->relationLoaded('images') ? $this->formatImages($model->images) : null,
        );
    }

    /**
     * Format product variants
     */
    private function formatVariants($variants): ?array
    {
        if ($variants === null || $variants->isEmpty()) {
            return null;
        }

        $variantMapper = new ProductVariantMapper();
        return $variantMapper->toArrayCollection($variants);
    }

    /**
     * Format product images
     */
    private function formatImages($images): ?array
    {
        if ($images === null || $images->isEmpty()) {
            return null;
        }

        $imageMapper = new ProductImageMapper();
        return $imageMapper->toArrayCollection($images);
    }
}
