<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceEntry;

class StoreFinanceEntryRequest extends FinanceFormRequest
{
    protected bool $partial = false;

    public function rules(): array
    {
        $req = $this->partial ? 'sometimes' : 'required';

        return [
            'accountId' => [$req, 'integer', 'min:1'],
            'kind' => [$req, 'string', 'in:' . implode(',', FinanceEntry::KINDS)],
            'direction' => ['nullable', 'string', 'in:in,out', $this->partial ? 'sometimes' : 'required_if:kind,adjustment'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:999999999'],
            'date' => [$req, 'date_format:Y-m-d', $this->notFuture()],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'party' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'accountId.required' => 'Choose an account.',
            'accountId.integer' => 'Choose an account.',
            'accountId.min' => 'Choose an account.',
            'kind.required' => 'Choose income, expense or adjustment.',
            'kind.in' => 'Choose income, expense or adjustment.',
            'direction.required_if' => 'Choose whether the adjustment adds (in) or removes (out) money.',
            'direction.in' => 'Choose whether the adjustment adds (in) or removes (out) money.',
            'amount.required' => 'Amount must be greater than zero.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.gt' => 'Amount must be greater than zero.',
            'amount.max' => 'Amount is too large.',
            'date.required' => 'Date is required.',
            'date.date_format' => 'Date must be a valid date (YYYY-MM-DD).',
            'category.max' => 'Category may not be longer than 100 characters.',
            'party.max' => 'Party may not be longer than 100 characters.',
            'reference.max' => 'Reference may not be longer than 100 characters.',
            'note.max' => 'The note may not be longer than 1000 characters.',
        ];
    }
}
