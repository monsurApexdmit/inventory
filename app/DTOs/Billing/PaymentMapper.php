<?php

namespace App\DTOs\Billing;

use App\DTOs\BaseMapper;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Payment model to PaymentDTO
 */
class PaymentMapper extends BaseMapper
{
    /**
     * Convert Payment model to DTO
     */
    public function toDTO(Model $model): PaymentDTO
    {
        if (!$model instanceof Payment) {
            throw new \InvalidArgumentException('Model must be instance of Payment');
        }

        return new PaymentDTO(
            id: $model->id,
            subscriptionId: $model->subscription_id,
            companyId: $model->company_id,
            amount: (int) $model->amount,
            status: $model->status,
            paymentMethod: $model->payment_method,
            paymentDate: $model->payment_date ? $this->formatTimestamp($model->payment_date) : null,
            invoiceNumber: $model->invoice_number,
            invoiceUrl: $model->invoice_url,
            stripePaymentId: $model->stripe_payment_id,
            description: $model->description,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
