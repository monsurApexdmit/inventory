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
    /** @param array{stock?:int,stockByLocation?:array,stockState?:string}|null $computed */
    public function toDTO(Model $model, ?array $computed = null): ProductDTO
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
            unitId: $model->unit_id,
            unitName: $model->relationLoaded('unit') && $model->unit ? $model->unit->unit_name : null,
            brandId: $model->brand_id,
            brandName: $model->relationLoaded('brand') && $model->brand ? $model->brand->brand_name : null,
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
            stock: (int) ($computed['stock'] ?? $model->stock),
            reorderPoint: (int) ($model->reorder_point ?? 0),
            sku: $model->sku,
            barcode: $model->barcode,
            barcodeType: $model->barcode_type,
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
            variants: $model->relationLoaded('variants') ? $this->formatVariants($model->variants, $model) : null,
            images: $model->relationLoaded('images') ? $this->formatImages($model->images) : null,
            offerPrice: $model->offer_price ? (float) $model->offer_price : null,
            offerType: $model->offer_type,
            attributes: $model->relationLoaded('attributes') ? $model->attributes->map(fn($a) => ['id' => $a->id, 'name' => $a->name])->values()->all() : null,
            isBundle: (bool) ($model->is_bundle ?? false),
            bundlePriceOverride: $model->bundle_price_override ? (float) $model->bundle_price_override : null,
            bundleItems: $model->relationLoaded('bundleItems') ? $this->formatBundleItems($model->bundleItems) : null,
            trackingType: $model->tracking_type ?? 'none',
            offerEndsAt: $model->offer_ends_at?->format('Y-m-d'),
            offerActive: $model->isOfferActive(),
            wholesalePrice: $model->wholesale_price !== null ? (float) $model->wholesale_price : null,
            minWholesaleQty: $model->min_wholesale_qty !== null ? (int) $model->min_wholesale_qty : null,
            availableInPos: (bool) ($model->available_in_pos ?? true),
            visibleOnline: (bool) ($model->visible_online ?? true),
            tag: $model->tag,
            stockByLocation: $computed['stockByLocation'] ?? null,
            stockState: $computed['stockState'] ?? null,
            currentPrice: \App\Services\Pricing\PriceService::resolve($model)['unit'],
        );
    }

    private function formatBundleItems($items): ?array
    {
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        return $items->map(function ($item) {
            return [
                'id'          => $item->id,
                'productId'   => $item->product_id,
                'productName' => $item->relationLoaded('product') ? $item->product->name : null,
                'productSku'  => $item->relationLoaded('product') ? $item->product->sku : null,
                'name'        => $item->relationLoaded('product') && $item->product ? $item->product->name : null,
                'stock'       => $this->bundleItemStock($item),
                'variantId'   => $item->variant_id,
                'variantName' => $item->relationLoaded('variant') && $item->variant ? $item->variant->name : null,
                'quantity'    => $item->quantity,
            ];
        })->values()->all();
    }

    private function bundleItemStock($item): ?int
    {
        if ($item->variant_id && $item->relationLoaded('variant') && $item->variant) {
            return (int) $item->variant->stock;
        }

        return $item->relationLoaded('product') && $item->product ? (int) $item->product->stock : null;
    }

    /**
     * Format product variants
     */
    private function formatVariants($variants, $parent = null): ?array
    {
        if ($variants === null || $variants->isEmpty()) {
            return null;
        }

        $variantMapper = new ProductVariantMapper();
        $rows = $variantMapper->toArrayCollection($variants);

        // Live offer + current price per variant; an expired offer prices at normal
        foreach ($variants->values() as $i => $variant) {
            if (!isset($rows[$i]) || !is_array($rows[$i])) {
                continue;
            }
            $product = $parent;
            if ($product) {
                $priced = \App\Services\Pricing\PriceService::resolve($product, $variant);
                $rows[$i]['offerActive'] = $priced['offerActive'];
                $rows[$i]['currentPrice'] = $priced['unit'];
            }
        }

        return $rows;
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
