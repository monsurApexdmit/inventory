<?php

namespace App\Http\Requests\Collection;

class ReorderCollectionsRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'integer|min:1',
        ];
    }
}
