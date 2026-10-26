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
                    'date'          => $payment->payment_date ? $payment->payment_date->format('Y-m-d') : null,
                    'method'        => $payment->payment_method,
                    'paymentMethod' => $payment->payment_method,
                    'paymentDate'   => $payment->payment_date ? $payment->payment_date->format('Y-m-d') : null,
                    'reference'     => $payment->reference,
                    'notes'         => $payment->notes,
                    'createdAt'     => $this->formatTimestamp($payment->created_at),
                ];
            }
        }

        $receipts = [];
        if ($model->relationLoaded('receipts')) {
            foreach ($model->receipts as $receipt) {
                $lines = $receipt->items ?? [];
                $text = collect($lines)->take(2)
                    ->map(fn($l) => ($l['productName'] ?? 'Item') . ' ×' . ($l['quantity'] ?? 0))->implode(', ');
                if (count($lines) > 2) {
                    $text .= ' +' . (count($lines) - 2) . ' more';
                }
                $receipts[] = [
                    'id'           => $receipt->id,
                    'date'         => $receipt->received_at ? $receipt->received_at->format('Y-m-d') : null,
                    'receivedAt'   => $this->formatTimestamp($receipt->received_at),
                    'locationId'   => $receipt->location_id,
                    'locationName' => $receipt->relationLoaded('location') && $receipt->location ? $receipt->location->name : null,
                    'itemsSummary' => $text,
                    'items'        => $lines,
                ];
            }
        }

        $itemsLoaded = $model->relationLoaded('items');
        $itemSubtotal = $itemsLoaded ? (float) $model->items->sum('subtotal') : (float) $model->total_amount;
        $totals = PurchaseOrder::computeTotals(
            $itemSubtotal,
            (string) ($model->discount_type ?? 'flat'),
            (float) $model->discount_value,
            (float) $model->tax_rate,
            (float) $model->shipping_cost,
        );

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
            discountType: (string) ($model->discount_type ?? 'flat'),
            discountValue: (float) $model->discount_value,
            discountAmount: $totals['discountAmount'],
            taxRate: (float) $model->tax_rate,
            taxAmount: $totals['taxAmount'],
            shippingCost: (float) $model->shipping_cost,
            subtotal: $totals['subtotal'],
            itemsSummary: $itemsLoaded ? $model->itemsSummary() : 'No items',
            unitsOrdered: $itemsLoaded ? (int) $model->items->sum('quantity_ordered') : 0,
            unitsReceived: $itemsLoaded ? (int) $model->items->sum('quantity_received') : 0,
            isOverdue: $model->isOverdue(),
            sentAt: $model->sent_at ? $this->formatTimestamp($model->sent_at) : null,
            receivedAt: $model->received_at ? $this->formatTimestamp($model->received_at) : null,
            receipts: $receipts,
        );
    }
}
