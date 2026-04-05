<?php

namespace App\Http\Requests\SalaryPayment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalaryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'paid_amount' => $this->paidAmount,
            'payment_date' => $this->paymentDate,
            'payment_method' => $this->paymentMethod,
        ]);
    }

    public function rules(): array
    {
        return [
            'amount' => 'sometimes|numeric|min:0',
            'paid_amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|string|in:Paid,Pending,Partial',
            'payment_date' => 'sometimes|nullable|date',
            'payment_method' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
