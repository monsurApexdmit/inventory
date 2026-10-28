<?php

namespace App\Repositories\Eloquent;

use App\Models\Collection;
use App\Repositories\Contracts\ICollectionRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CollectionRepository implements ICollectionRepository
{
    public function __construct(private readonly Collection $model)
    {
    }

    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->where('company_id', $companyId);

        if (!empty($filters['search']) && is_string($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('slug', 'like', "%{$s}%"));
        }
        if (!empty($filters['type']) && in_array($filters['type'], Collection::TYPES, true)) {
            $query->where('type', $filters['type']);
        }
        if (array_key_exists('published', $filters) && $filters['published'] !== '' && $filters['published'] !== null) {
            $published = filter_var($filters['published'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($published !== null) {
                $query->where('is_published', $published);
            }
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? $filters['per_page'] ?? 20)));

        return $query->orderBy('sort_order')->orderBy('id')->paginate($limit);
    }

    public function simpleByCompany(int $companyId): array
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'slug', 'type', 'is_published'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'type' => $c->type,
                'isPublished' => (bool) $c->is_published,
            ])->all();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Collection
    {
        return $this->model->newQuery()->where('company_id', $companyId)->find($id);
    }

    public function findPublishedBySlugOrId(string $slugOrId, int $companyId): ?Collection
    {
        $query = $this->model->newQuery()->where('company_id', $companyId)->where('is_published', true);
        if (ctype_digit($slugOrId)) {
            $query->where(fn ($q) => $q->where('slug', $slugOrId)->orWhere('id', (int) $slugOrId));
        } else {
            $query->where('slug', $slugOrId);
        }

        return $query->first();
    }

    public function publishedByCompany(int $companyId, bool $homepageOnly = false): \Illuminate\Support\Collection
    {
        return $this->model->newQuery()->where('company_id', $companyId)->where('is_published', true)
            ->when($homepageOnly, fn ($q) => $q->where('show_on_homepage', true))
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    public function slugExists(string $slug, int $companyId, ?int $excludeId = null): bool
    {
        return $this->model->newQuery()->where('company_id', $companyId)->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    public function nextSortOrder(int $companyId): int
    {
        return (int) $this->model->newQuery()->where('company_id', $companyId)->max('sort_order') + 1;
    }

    public function setSortOrders(array $ids, int $companyId): void
    {
        DB::transaction(function () use ($ids, $companyId) {
            foreach (array_values($ids) as $i => $id) {
                $this->model->newQuery()->where('company_id', $companyId)->where('id', $id)->update(['sort_order' => $i + 1]);
            }
        });
    }

    public function countOwned(array $ids, int $companyId): int
    {
        return $this->model->newQuery()->where('company_id', $companyId)->whereIn('id', $ids)->count();
    }

    public function create(array $data): Collection
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(Collection $collection, array $data): Collection
    {
        $collection->fill($data)->save();

        return $collection->refresh();
    }

    public function delete(Collection $collection): void
    {
        DB::transaction(function () use ($collection) {
            DB::table('collection_product')->where('collection_id', $collection->id)->delete();
            $collection->delete();
        });
    }

    public function manualProductIds(int $collectionId): array
    {
        return DB::table('collection_product')->where('collection_id', $collectionId)
            ->orderBy('position')->orderBy('id')
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    public function syncProducts(int $collectionId, array $productIds): void
    {
        DB::transaction(function () use ($collectionId, $productIds) {
            DB::table('collection_product')->where('collection_id', $collectionId)->delete();
            $now = now();
            $rows = [];
            foreach (array_values($productIds) as $i => $pid) {
                $rows[] = [
                    'collection_id' => $collectionId,
                    'product_id' => (int) $pid,
                    'position' => $i + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('collection_product')->insert($chunk);
            }
        });
    }
}
