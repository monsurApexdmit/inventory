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
            'paidAmount' => $this->paidAmount ?? $this->paid_amount,
            'paymentDate' => $this->paymentDate ?? $this->payment_date,
            'paymentMethod' => $this->paymentMethod ?? $this->payment_method,
        ]);
    }

    public function rules(): array
    {
        return [
            'staffId' => 'required|integer|min:1',
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'amount' => 'required|numeric|min:0',
            'paidAmount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:paid,partial,Paid,Pending,Partial',
            'paymentDate' => 'nullable|date',
            'paymentMethod' => 'nullable|string',
            'notes' => 'nullable|string',
            'remarks' => 'nullable|string',
        ];
    }
}
