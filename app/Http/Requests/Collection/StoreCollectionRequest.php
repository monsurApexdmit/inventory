<?php

namespace App\Http\Requests\Collection;

use App\Models\Collection;

class StoreCollectionRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:5000',
            'image' => ['nullable', $this->imageRule()],
            'type' => 'required|in:' . implode(',', Collection::TYPES),
            'match' => 'nullable|in:' . implode(',', Collection::MATCHES),
            'rules' => 'nullable',
            'productSort' => 'nullable|in:' . implode(',', Collection::SORTS),
            'isPublished' => 'nullable|boolean',
            'showOnHomepage' => 'nullable|boolean',
            'seoTitle' => 'nullable|string|max:255',
            'seoDescription' => 'nullable|string|max:500',
            'productIds' => 'nullable|array|max:1000',
            'productIds.*' => 'integer|min:1',
        ];
    }
}
