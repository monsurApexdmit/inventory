<?php

namespace App\DTOs\Payment;

use App\DTOs\BaseMapper;
use App\Models\DuePayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting DuePayment model to DuePaymentDTO
 */
class DuePaymentMapper extends BaseMapper
{
    /**
     * Convert DuePayment model to DTO
     */
    public function toDTO(Model $model): DuePaymentDTO
    {
        if (!$model instanceof DuePayment) {
            throw new \InvalidArgumentException('Model must be instance of DuePayment');
        }

        return new DuePaymentDTO(
            id: $model->id,
            companyId: $model->company_id,
            payableType: $model->payable_type,
            payableId: $model->payable_id,
            direction: $model->direction,
            amount: (float) $model->amount,
            paymentMethod: $model->payment_method,
            paymentDate: $this->formatTimestamp($model->payment_date),
            reference: $model->reference,
            notes: $model->notes,
            recordedBy: $model->recorded_by,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
