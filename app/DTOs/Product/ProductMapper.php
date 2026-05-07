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

        // Extract location data if available
        $location = null;
        $locationName = null;
        if ($model->relationLoaded('location') && $model->location) {
            $location = [
                'id' => $model->location->id,
                'name' => $model->location->name,
                'address' => $model->location->address,
            ];
            $locationName = $model->location->name;
        }

        // Extract inventory from variants (flatten inventory array from all variants)
        $inventory = [];
        if ($model->relationLoaded('variants') && $model->variants) {
            foreach ($model->variants as $variant) {
                if ($variant->relationLoaded('inventory') && $variant->inventory) {
                    foreach ($variant->inventory as $inv) {
                        $inventory[] = [
                            'warehouseId' => $inv->warehouse_id,
                            'quantity' => $inv->quantity,
                        ];
                    }
                }
            }
        }

        return new ProductDTO(
            id: $model->id,
            companyId: $model->company_id,
            categoryId: $model->category_id,
            categoryName: $model->relationLoaded('category') && $model->category ? $model->category->category_name : null,
            vendorId: $model->vendor_id,
            vendorName: $model->relationLoaded('vendor') && $model->vendor ? $model->vendor->name : null,
            locationId: $model->location_id,
            name: $model->name,
            description: $model->description,
            price: (float) $model->price,
            salePrice: (float) $model->sale_price,
            costPrice: (float) $model->cost_price,
            profitMargin: $model->profit_margin ? (float) $model->profit_margin : null,
            marginType: $model->margin_type,
            stock: (int) $model->stock,
            sku: $model->sku,
            barcode: $model->barcode,
            published: (bool) $model->published,
            image: $image,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            locationName: $locationName,
            isHotDeal: (bool) $model->is_hot_deal,
            isBestSeller: (bool) $model->is_best_seller,
            isFeatured: (bool) $model->is_featured,
            dealLabel: $model->deal_label,
            receiptNumber: $model->receipt_number,
            location: $location,
            inventory: !empty($inventory) ? $inventory : null,
            variants: $model->relationLoaded('variants') ? $this->formatVariants($model->variants) : null,
            images: $model->relationLoaded('images') ? $this->formatImages($model->images) : null,
            offerPrice: $model->offer_price ? (float) $model->offer_price : null,
            offerType: $model->offer_type,
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
