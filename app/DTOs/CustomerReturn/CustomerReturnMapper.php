<?php

namespace App\DTOs\CustomerReturn;

use App\DTOs\BaseMapper;
use App\Models\CustomerReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting CustomerReturn model to CustomerReturnDTO
 */
class CustomerReturnMapper extends BaseMapper
{
    /**
     * Convert CustomerReturn model to DTO
     */
    public function toDTO(Model $model): CustomerReturnDTO
    {
        if (!$model instanceof CustomerReturn) {
            throw new \InvalidArgumentException('Model must be instance of CustomerReturn');
        }

        return new CustomerReturnDTO(
            id: $model->id,
            companyId: $model->company_id,
            customerId: $model->customer_id,
            returnNumber: $model->return_number,
            customerName: $model->customer_name,
            orderId: $model->order_id,
            orderNumber: $model->order_number,
            totalAmount: (float) $model->total_amount,
            status: $model->status,
            processedDate: $model->processed_date ? $this->formatTimestamp($model->processed_date) : null,
            refundMethod: $model->refund_method,
            notes: $model->notes,
            processedBy: $model->processed_by,
            requestDate: $this->formatTimestamp($model->request_date),
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            items: $model->relationLoaded('items') ? $this->formatItems($model->items) : null,
        );
    }

    /**
     * Format return items
     */
    private function formatItems($items): ?array
    {
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        $itemMapper = new CustomerReturnItemMapper();
        return $itemMapper->toArrayCollection($items);
    }
}
