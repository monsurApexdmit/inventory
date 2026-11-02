<?php

namespace App\Http\Requests\Collection;

use App\Models\Collection;

class PreviewCollectionRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|in:' . implode(',', Collection::TYPES),
            'match' => 'nullable|in:' . implode(',', Collection::MATCHES),
            'rules' => 'nullable',
            'productIds' => 'nullable|array|max:1000',
            'productIds.*' => 'integer|min:1',
            'productSort' => 'nullable|in:' . implode(',', Collection::SORTS),
            'limit' => 'nullable|integer|min:1|max:50',
        ];
    }
}
