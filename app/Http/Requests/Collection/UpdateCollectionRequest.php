<?php

namespace App\Http\Requests\Collection;

use App\Models\Collection;

class UpdateCollectionRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:150',
            'slug' => 'sometimes|nullable|string|max:191',
            'description' => 'sometimes|nullable|string|max:5000',
            'image' => ['sometimes', 'nullable', $this->imageRule()],
            'type' => 'sometimes|required|in:' . implode(',', Collection::TYPES),
            'match' => 'sometimes|nullable|in:' . implode(',', Collection::MATCHES),
            'rules' => 'sometimes|nullable',
            'productSort' => 'sometimes|nullable|in:' . implode(',', Collection::SORTS),
            'isPublished' => 'sometimes|nullable|boolean',
            'showOnHomepage' => 'sometimes|nullable|boolean',
            'seoTitle' => 'sometimes|nullable|string|max:255',
            'seoDescription' => 'sometimes|nullable|string|max:500',
            'productIds' => 'sometimes|nullable|array|max:1000',
            'productIds.*' => 'integer|min:1',
        ];
    }
}
