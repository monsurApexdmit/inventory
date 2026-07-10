<?php

namespace App\DTOs\PurchaseOrder;

use App\DTOs\BaseMapper;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderMapper extends BaseMapper
{
    public function toDTO(Model $model): PurchaseOrderDTO
    {
        /** @var PurchaseOrder $model */
        $items = [];
        if ($model->relationLoaded('items')) {
            foreach ($model->items as $item) {
                $items[] = [
                    'id'               => $item->id,
                    'productId'        => $item->product_id,
                    'productName'      => $item->relationLoaded('product') ? $item->product->name : null,
                    'productSku'       => $item->relationLoaded('product') ? $item->product->sku : null,
                    'variantId'        => $item->variant_id,
                    'variantName'      => $item->relationLoaded('variant') && $item->variant ? $item->variant->name : null,
                    'quantityOrdered'  => $item->quantity_ordered,
                    'quantityReceived' => $item->quantity_received,
                    'unitCost'         => $item->unit_cost,
                    'subtotal'         => $item->subtotal,
                ];
            }
        }

        return new PurchaseOrderDTO(
            id: $model->id,
            companyId: $model->company_id,
            vendorId: $model->vendor_id,
            vendorName: $model->relationLoaded('vendor') ? $model->vendor->name : '',
            locationId: $model->location_id,
            locationName: $model->relationLoaded('location') && $model->location ? $model->location->name : null,
            poNumber: $model->po_number,
            status: $model->status,
            expectedDate: $model->expected_date ? $model->expected_date->format('Y-m-d') : null,
            notes: $model->notes,
            totalAmount: (float) $model->total_amount,
            items: $items,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
