<?php

namespace App\Http\Requests\Finance;

use App\Models\FinanceAccount;

class StoreFinanceAccountRequest extends FinanceFormRequest
{
    protected array $booleans = ['isDefault', 'isActive'];

    protected bool $partial = false;

    public function rules(): array
    {
        $req = $this->partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100'],
            'type' => [$req, 'string', 'in:' . implode(',', FinanceAccount::TYPES)],
            'methodKeys' => ['sometimes', 'nullable', 'array', 'max:20'],
            'methodKeys.*' => ['string', 'max:30'],
            'openingBalance' => ['sometimes', 'nullable', 'numeric', 'between:-999999999,999999999'],
            'isDefault' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Account name is required.',
            'name.string' => 'Account name must be text.',
            'name.max' => 'Account name may not be longer than 100 characters.',
            'type.required' => 'Choose an account type: cash, bank, mobile_wallet, card or other.',
            'type.in' => 'Choose an account type: cash, bank, mobile_wallet, card or other.',
            'methodKeys.array' => 'Payment methods must be a list.',
            'methodKeys.max' => 'An account can have at most 20 payment methods.',
            'methodKeys.*.string' => 'Each payment method must be text.',
            'methodKeys.*.max' => 'Each payment method may not be longer than 30 characters.',
            'openingBalance.numeric' => 'Opening balance must be a number.',
            'openingBalance.between' => 'Opening balance is too large.',
            'isDefault.boolean' => 'Default must be true or false.',
            'isActive.boolean' => 'Active must be true or false.',
            'note.max' => 'The note may not be longer than 500 characters.',
        ];
    }
}
