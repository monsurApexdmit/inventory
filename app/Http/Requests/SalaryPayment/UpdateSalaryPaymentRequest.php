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
        $merged = [];

        if ($this->has('paid_amount') && !$this->has('paidAmount')) {
            $merged['paidAmount'] = $this->paid_amount;
        }
        if ($this->has('payment_date') && !$this->has('paymentDate')) {
            $merged['paymentDate'] = $this->payment_date;
        }
        if ($this->has('payment_method') && !$this->has('paymentMethod')) {
            $merged['paymentMethod'] = $this->payment_method;
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => 'sometimes|numeric|min:0',
            'paidAmount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|string|in:paid,partial,Paid,Pending,Partial',
            'paymentDate' => 'sometimes|nullable|date',
            'paymentMethod' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
            'remarks' => 'sometimes|nullable|string',
        ];
    }
}
