<?php

namespace App\Http\Requests\Finance;

class StoreFinanceTransferRequest extends FinanceFormRequest
{
    protected bool $partial = false;

    public function rules(): array
    {
        $req = $this->partial ? 'sometimes' : 'required';

        return [
            'fromAccountId' => [$req, 'integer', 'min:1'],
            'toAccountId' => [$req, 'integer', 'min:1'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:999999999'],
            'date' => [$req, 'date_format:Y-m-d', $this->notFuture()],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'fromAccountId.required' => 'Choose the account the money leaves.',
            'fromAccountId.integer' => 'Choose the account the money leaves.',
            'fromAccountId.min' => 'Choose the account the money leaves.',
            'toAccountId.required' => 'Choose the account the money goes to.',
            'toAccountId.integer' => 'Choose the account the money goes to.',
            'toAccountId.min' => 'Choose the account the money goes to.',
            'amount.required' => 'Amount must be greater than zero.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.gt' => 'Amount must be greater than zero.',
            'amount.max' => 'Amount is too large.',
            'date.required' => 'Date is required.',
            'date.date_format' => 'Date must be a valid date (YYYY-MM-DD).',
            'reference.max' => 'Reference may not be longer than 100 characters.',
            'note.max' => 'The note may not be longer than 1000 characters.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $from = $this->input('fromAccountId');
            $to = $this->input('toAccountId');
            if ($from !== null && $to !== null && (int) $from === (int) $to && !$v->errors()->has('fromAccountId')) {
                $v->errors()->add('toAccountId', 'Pick two different accounts.');
            }
        });
    }
}
