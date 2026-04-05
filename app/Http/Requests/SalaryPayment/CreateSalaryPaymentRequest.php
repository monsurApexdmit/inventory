<?php

namespace App\Http\Requests\SalaryPayment;

use Illuminate\Foundation\Http\FormRequest;

class CreateSalaryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'staff_id' => $this->staffId,
            'paid_amount' => $this->paidAmount,
            'payment_date' => $this->paymentDate,
            'payment_method' => $this->paymentMethod,
        ]);
    }

    public function rules(): array
    {
        return [
            'staff_id' => 'required|integer|min:1',
            'month' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Paid,Pending,Partial',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string',
        ];
    }
}
