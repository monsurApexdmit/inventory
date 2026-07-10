<?php

namespace App\DTOs\Category;

use App\DTOs\BaseMapper;
use App\Models\Category;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Category model to CategoryDTO
 */
class CategoryMapper extends BaseMapper
{
    /**
     * Convert Category model to DTO
     */
    public function toDTO(Model $model): CategoryDTO
    {
        if (!$model instanceof Category) {
            throw new \InvalidArgumentException('Model must be instance of Category');
        }

        $parent = null;
        if ($model->relationLoaded('parent') && $model->parent) {
            $parent = [
                'id' => $model->parent->id,
                'categoryName' => $model->parent->category_name,
            ];
        }

        return new CategoryDTO(
            id: $model->id,
            companyId: $model->company_id,
            categoryName: $model->category_name,
            parentId: $model->parent_id,
            status: (bool) $model->status,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            parent: $parent,
        );
    }
}
