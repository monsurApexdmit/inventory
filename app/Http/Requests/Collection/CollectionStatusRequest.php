<?php

namespace App\Http\Requests\Collection;

class CollectionStatusRequest extends CollectionFormRequest
{
    public function rules(): array
    {
        return ['isPublished' => 'sometimes|nullable|boolean'];
    }
}
