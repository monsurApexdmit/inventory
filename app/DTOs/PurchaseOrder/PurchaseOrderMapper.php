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

        $payments = [];
        if ($model->relationLoaded('payments')) {
            foreach ($model->payments as $payment) {
                $payments[] = [
                    'id'            => $payment->id,
                    'amount'        => (float) $payment->amount,
                    'paymentMethod' => $payment->payment_method,
                    'paymentDate'   => $payment->payment_date ? $payment->payment_date->format('Y-m-d') : null,
                    'reference'     => $payment->reference,
                    'notes'         => $payment->notes,
                    'createdAt'     => $this->formatTimestamp($payment->created_at),
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
            paidAmount: (float) $model->paid_amount,
            dueAmount: (float) $model->due_amount,
            paymentStatus: $model->payment_status ?? 'pending',
            payments: $payments,
            items: $items,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
