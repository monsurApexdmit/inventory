<?php

namespace App\DTOs\SerialBatch;

use App\Models\ProductBatch;
use App\Models\ProductSerial;
use Illuminate\Database\Eloquent\Model;

class SerialBatchMapper
{
    public function toSerialDTO(ProductSerial $model): SerialDTO
    {
        return new SerialDTO(
            id: $model->id,
            companyId: $model->company_id,
            productId: $model->product_id,
            productName: $model->relationLoaded('product') && $model->product ? $model->product->name : '',
            variantId: $model->variant_id,
            variantName: $model->relationLoaded('variant') && $model->variant ? $model->variant->name : null,
            locationId: $model->location_id,
            locationName: $model->relationLoaded('location') && $model->location ? $model->location->name : null,
            serialNumber: $model->serial_number,
            status: $model->status,
            purchaseOrderNumber: $model->purchase_order_number,
            receivedDate: $model->received_date?->toDateString(),
            soldInSellId: $model->sold_in_sell_id,
            soldDate: $model->sold_date?->toDateString(),
            notes: $model->notes,
            createdAt: $model->created_at->toIso8601String(),
            updatedAt: $model->updated_at->toIso8601String(),
        );
    }

    public function toBatchDTO(ProductBatch $model): BatchDTO
    {
        return new BatchDTO(
            id: $model->id,
            companyId: $model->company_id,
            productId: $model->product_id,
            productName: $model->relationLoaded('product') && $model->product ? $model->product->name : '',
            variantId: $model->variant_id,
            variantName: $model->relationLoaded('variant') && $model->variant ? $model->variant->name : null,
            locationId: $model->location_id,
            locationName: $model->relationLoaded('location') && $model->location ? $model->location->name : null,
            batchNumber: $model->batch_number,
            quantityReceived: (int) $model->quantity_received,
            quantityRemaining: (int) $model->quantity_remaining,
            manufactureDate: $model->manufacture_date?->toDateString(),
            expiryDate: $model->expiry_date?->toDateString(),
            purchaseOrderNumber: $model->purchase_order_number,
            receivedDate: $model->received_date?->toDateString(),
            notes: $model->notes,
            isExpired: $model->isExpired(),
            isExpiringSoon: $model->isExpiringSoon(),
            createdAt: $model->created_at->toIso8601String(),
            updatedAt: $model->updated_at->toIso8601String(),
        );
    }
}
