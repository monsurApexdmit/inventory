<?php

namespace App\DTOs\Collection;

use App\DTOs\BaseDTO;

/** Admin-facing collection (camelCase). Detail-only fields are null on list rows and then omitted. */
class CollectionDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $companyId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $image,
        public readonly ?string $imageUrl,
        public readonly string $type,
        public readonly string $match,
        public readonly array $rules,
        public readonly int $sortOrder,
        public readonly string $productSort,
        public readonly bool $isPublished,
        public readonly bool $showOnHomepage,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
        public readonly ?int $productCount,
        public readonly ?int $visibleProductCount,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?array $productIds = null,
        public readonly ?array $manualProducts = null,
    ) {}

    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'imageUrl' => $this->imageUrl,
            'type' => $this->type,
            'match' => $this->match,
            'rules' => $this->rules,
            'sortOrder' => $this->sortOrder,
            'productSort' => $this->productSort,
            'isPublished' => $this->isPublished,
            'showOnHomepage' => $this->showOnHomepage,
            'seoTitle' => $this->seoTitle,
            'seoDescription' => $this->seoDescription,
            'productCount' => $this->productCount,
            'visibleProductCount' => $this->visibleProductCount,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
        if ($this->productIds !== null) {
            $out['productIds'] = $this->productIds;
            $out['manualProducts'] = $this->manualProducts ?? [];
        }

        return $out;
    }
}
