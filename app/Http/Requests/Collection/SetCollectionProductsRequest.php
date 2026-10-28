<?php

namespace App\Http\Requests\Collection;

class SetCollectionProductsRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return [
            'productIds' => 'present|array|max:1000',
            'productIds.*' => 'integer|min:1',
        ];
    }

    public function messages(): array
    {
        return parent::messages() + ['productIds.present' => 'Send productIds (the full ordered list, [] to empty it).'];
    }
}
