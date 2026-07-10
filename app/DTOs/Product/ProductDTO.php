<?php

namespace App\DTOs\Product;

use App\DTOs\BaseDTO;

/**
 * DTO for Product Response
 */
class ProductDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly ?int $vendorId,
        public readonly ?string $vendorName,
        public readonly ?int $locationId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly float $price,
        public readonly float $salePrice,
        public readonly float $costPrice,
        public readonly ?float $profitMargin,
        public readonly ?string $marginType,
        public readonly int $stock,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly bool $published,
        public readonly ?string $image,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly int $reorderPoint = 0,
        public readonly ?string $locationName = null,
        public readonly bool $isHotDeal = false,
        public readonly bool $isBestSeller = false,
        public readonly bool $isFeatured = false,
        public readonly ?string $dealLabel = null,
        public readonly ?string $receiptNumber = null,
        public readonly ?array $location = null,
        public readonly ?array $inventory = null,
        public readonly ?array $variants = null,
        public readonly ?array $images = null,
        public readonly ?float $offerPrice = null,
        public readonly ?string $offerType = null,
        public readonly ?array $attributes = null,
        public readonly bool $isBundle = false,
        public readonly ?float $bundlePriceOverride = null,
        public readonly ?array $bundleItems = null,
        public readonly string $trackingType = 'none',
    ) {}

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'companyId'     => $this->companyId,
            'categoryId'    => $this->categoryId,
            'categoryName'  => $this->categoryName,
            'vendorId'      => $this->vendorId,
            'vendorName'    => $this->vendorName,
            'locationId'    => $this->locationId,
            'locationName'  => $this->locationName,
            'name'          => $this->name,
            'description'   => $this->description,
            'price'         => $this->price,
            'salePrice'     => $this->salePrice,
            'offerPrice'    => $this->offerPrice,
            'offerType'     => $this->offerType,
            'costPrice'     => $this->costPrice,
            'profitMargin'  => $this->profitMargin,
            'marginType'    => $this->marginType,
            'stock'         => $this->stock,
            'reorderPoint'  => $this->reorderPoint,
            'sku'           => $this->sku,
            'barcode'       => $this->barcode,
            'published'     => $this->published,
            'isHotDeal'     => $this->isHotDeal,
            'isBestSeller'  => $this->isBestSeller,
            'isFeatured'    => $this->isFeatured,
            'dealLabel'     => $this->dealLabel,
            'receiptNumber' => $this->receiptNumber,
            'image'         => $this->image,
            'createdAt'     => $this->createdAt,
            'updatedAt'     => $this->updatedAt,
            'category'      => $this->categoryId !== null ? ['id' => $this->categoryId, 'name' => $this->categoryName] : null,
            'location'      => $this->location,
            'inventory'     => $this->inventory,
            'variants'      => $this->variants ?? [],
            'images'        => $this->images ?? [],
            'attributes'          => $this->attributes ?? [],
            'isBundle'            => $this->isBundle,
            'bundlePriceOverride' => $this->bundlePriceOverride,
            'bundleItems'         => $this->bundleItems ?? [],
            'trackingType'        => $this->trackingType,
        ];
    }
}
