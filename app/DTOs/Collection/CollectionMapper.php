<?php

namespace App\DTOs\Collection;

use App\DTOs\BaseMapper;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CollectionMapper extends BaseMapper
{
    /**
     * Counts / detail extras are passed in by the service:
     * $extra = ['productCount' => int, 'visibleProductCount' => int, 'productIds' => [...], 'manualProducts' => [...]]
     */
    public function toDTO(Model $model, array $extra = []): CollectionDTO
    {
        if (!$model instanceof Collection) {
            throw new \InvalidArgumentException('Model must be instance of Collection');
        }

        return new CollectionDTO(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            name: (string) $model->name,
            slug: (string) $model->slug,
            description: $model->description,
            image: $model->image,
            imageUrl: self::imageUrl($model->image),
            type: $model->type ?: 'manual',
            match: $model->match ?: 'all',
            rules: $model->type === 'automatic' && is_array($model->rules) ? array_values($model->rules) : [],
            sortOrder: (int) $model->sort_order,
            productSort: $model->product_sort ?: 'manual',
            isPublished: (bool) $model->is_published,
            showOnHomepage: (bool) $model->show_on_homepage,
            seoTitle: $model->seo_title,
            seoDescription: $model->seo_description,
            productCount: $extra['productCount'] ?? null,
            visibleProductCount: $extra['visibleProductCount'] ?? null,
            createdAt: $model->created_at?->toIso8601String(),
            updatedAt: $model->updated_at?->toIso8601String(),
            productIds: $extra['productIds'] ?? null,
            manualProducts: $extra['manualProducts'] ?? null,
        );
    }

    /** Absolute URL for a stored image path; absolute URLs pass through. */
    public static function imageUrl(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $image) || str_starts_with($image, 'data:')) {
            return $image;
        }

        return Storage::disk('public')->url(ltrim(preg_replace('#^/?storage/#', '', $image), '/'));
    }
}
