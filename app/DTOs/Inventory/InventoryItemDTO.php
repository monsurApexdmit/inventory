<?php

namespace App\DTOs\Inventory;

use App\DTOs\BaseDTO;

class InventoryItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly int $productId,
        public readonly string $productName,
        public readonly string $variantName,
        public readonly string $sku,
        public readonly ?string $barcode,
        public readonly int $stock,
        public readonly array $inventory,
        public readonly int $reorderPoint = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'productId' => $this->productId,
            'productName' => $this->productName,
            'variantName' => $this->variantName,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'stock' => $this->stock,
            'reorderPoint' => $this->reorderPoint,
            'inventory' => array_map(
                fn($item) => $item instanceof LocationInventoryDTO ? $item->toArray() : $item,
                $this->inventory
            ),
        ];
    }
}
