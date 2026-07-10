<?php

namespace App\DTOs\Salary;

use App\DTOs\BaseMapper;
use App\Models\SalaryPayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting SalaryPayment model to SalaryPaymentDTO
 * Handles all transformation logic from database model to API response
 */
class SalaryPaymentMapper extends BaseMapper
{
    /**
     * Convert SalaryPayment model to DTO
     */
    public function toDTO(Model $model): SalaryPaymentDTO
    {
        if (!$model instanceof SalaryPayment) {
            throw new \InvalidArgumentException('Model must be instance of SalaryPayment');
        }

        return new SalaryPaymentDTO(
            id: $model->id,
            staffId: $model->staff_id,
            month: $model->month,
            amount: (float) $model->amount,
            paidAmount: (float) $model->paid_amount,
            status: $model->status,
            paymentDate: $this->formatTimestamp($model->payment_date),
            remarks: $model->remarks,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            staff: $model->relationLoaded('staff') && $model->staff ? $this->formatStaffRelation($model->staff) : null,
        );
    }

    /**
     * Format staff relation for nested response
     */
    private function formatStaffRelation($staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
        ];
    }
}
