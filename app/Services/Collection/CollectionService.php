<?php

namespace App\Services\Collection;

use App\DTOs\Collection\CollectionDTO;
use App\DTOs\Collection\CollectionMapper;
use App\Models\Collection;
use App\Models\Product;
use App\Repositories\Contracts\ICollectionRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Admin CRUD, manual products, previews and storefront reads for collections. */
class CollectionService
{
    private readonly CollectionMapper $mapper;

    public function __construct(
        private readonly ICollectionRepository $repository,
        private readonly CollectionRuleEngine $engine,
    ) {
        $this->mapper = new CollectionMapper();
    }

    // ------------------------------------------------------------------
    // Admin reads
    // ------------------------------------------------------------------

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->paginateByCompany($companyId, $filters);

        return [
            'data' => array_map(fn (Collection $c) => $this->toDTO($c)->toArray(), $paginated->items()),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function simple(int $companyId): array
    {
        return $this->repository->simpleByCompany($companyId);
    }

    public function get(int $id, int $companyId): CollectionDTO
    {
        return $this->toDTO($this->find($id, $companyId), true);
    }

    // ------------------------------------------------------------------
    // Admin writes
    // ------------------------------------------------------------------

    public function create(int $companyId, array $data): CollectionDTO
    {
        $type = $data['type'];
        $rules = $type === 'automatic' ? ($data['rules'] ?? []) : [];
        if ($type === 'automatic' && !$rules) {
            $this->fail('rules', 'Add at least one rule for an automatic collection.');
        }
        if ($type === 'automatic' && !empty($data['productIds'])) {
            $this->fail('productIds', 'Products can only be picked for a manual collection.');
        }
        $productIds = $type === 'manual' ? $this->assertProducts($data['productIds'] ?? [], $companyId) : [];

        $slug = $this->resolveSlug($data['slug'] ?? null, $data['name'], $companyId);
        $image = $this->storeImage($data['image'] ?? null);

        $collection = DB::transaction(function () use ($companyId, $data, $type, $rules, $slug, $image, $productIds) {
            $collection = $this->repository->create([
                'company_id' => $companyId,
                'name' => trim($data['name']),
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'image' => $image,
                'type' => $type,
                'match' => $data['match'] ?? 'all',
                'rules' => $rules,
                'sort_order' => $this->repository->nextSortOrder($companyId),
                'product_sort' => $data['productSort'] ?? ($type === 'manual' ? 'manual' : 'best_selling'),
                'is_published' => (bool) ($data['isPublished'] ?? false),
                'show_on_homepage' => (bool) ($data['showOnHomepage'] ?? false),
                'seo_title' => $data['seoTitle'] ?? null,
                'seo_description' => $data['seoDescription'] ?? null,
            ]);
            if ($productIds) {
                $this->repository->syncProducts($collection->id, $productIds);
            }

            return $collection;
        });

        return $this->toDTO($collection, true);
    }

    public function update(int $id, int $companyId, array $data): CollectionDTO
    {
        $collection = $this->find($id, $companyId);
        $type = $data['type'] ?? $collection->type;

        $patch = [];
        if (array_key_exists('name', $data)) {
            $patch['name'] = trim($data['name']);
        }
        if (array_key_exists('slug', $data)) {
            $patch['slug'] = $this->resolveSlug($data['slug'], $patch['name'] ?? $collection->name, $companyId, $collection->id, $collection->slug);
        }
        foreach ([
            'description' => 'description', 'match' => 'match', 'productSort' => 'product_sort',
            'seoTitle' => 'seo_title', 'seoDescription' => 'seo_description',
        ] as $in => $col) {
            if (array_key_exists($in, $data)) {
                $patch[$col] = $data[$in];
            }
        }
        if (array_key_exists('match', $patch) && $patch['match'] === null) {
            $patch['match'] = 'all';
        }
        if (array_key_exists('product_sort', $patch) && $patch['product_sort'] === null) {
            $patch['product_sort'] = $type === 'manual' ? 'manual' : 'best_selling';
        }
        foreach (['isPublished' => 'is_published', 'showOnHomepage' => 'show_on_homepage'] as $in => $col) {
            if (array_key_exists($in, $data) && $data[$in] !== null) {
                $patch[$col] = (bool) $data[$in];
            }
        }
        $patch['type'] = $type;

        if ($type === 'automatic') {
            $rules = array_key_exists('rules', $data) ? ($data['rules'] ?? []) : ($collection->rules ?? []);
            if (!$rules) {
                $this->fail('rules', 'Add at least one rule for an automatic collection.');
            }
            if (!empty($data['productIds'])) {
                $this->fail('productIds', 'Products can only be picked for a manual collection.');
            }
            $patch['rules'] = array_values($rules);
        } else {
            $patch['rules'] = [];
        }

        $productIds = null;
        if ($type === 'manual' && array_key_exists('productIds', $data) && $data['productIds'] !== null) {
            $productIds = $this->assertProducts($data['productIds'], $companyId);
        }

        $oldImage = $collection->image;
        if (array_key_exists('image', $data)) {
            $patch['image'] = $this->storeImage($data['image']);
        }

        DB::transaction(function () use ($collection, $patch, $productIds) {
            $this->repository->update($collection, $patch);
            if ($productIds !== null) {
                $this->repository->syncProducts($collection->id, $productIds);
            }
        });

        if (array_key_exists('image', $patch) && $oldImage !== $patch['image']) {
            $this->deleteImage($oldImage);
        }

        return $this->get($id, $companyId);
    }

    public function setStatus(int $id, int $companyId, ?bool $isPublished): CollectionDTO
    {
        $collection = $this->find($id, $companyId);
        $this->repository->update($collection, ['is_published' => $isPublished ?? !$collection->is_published]);

        return $this->toDTO($collection->refresh());
    }

    public function setProducts(int $id, int $companyId, array $productIds): CollectionDTO
    {
        $collection = $this->find($id, $companyId);
        if ($collection->type === 'automatic') {
            $this->fail('productIds', 'Products can only be picked for a manual collection.');
        }
        $this->repository->syncProducts($collection->id, $this->assertProducts($productIds, $companyId));
        $collection->touch();

        return $this->get($id, $companyId);
    }

    public function reorder(array $ids, int $companyId): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($this->repository->countOwned($ids, $companyId) !== count($ids)) {
            $this->fail('ids', 'One or more collections were not found.');
        }
        $this->repository->setSortOrders($ids, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $collection = $this->find($id, $companyId);
        $image = $collection->image;
        $this->repository->delete($collection);
        $this->deleteImage($image);
    }

    // ------------------------------------------------------------------
    // Previews (admin: every product, flags storefront-hidden ones)
    // ------------------------------------------------------------------

    /** Preview an unsaved definition (first page only). */
    public function previewDefinition(int $companyId, array $data): array
    {
        $type = $data['type'];
        $definition = [
            'type' => $type,
            'match' => $data['match'] ?? 'all',
            'rules' => $type === 'automatic' ? ($data['rules'] ?? []) : [],
            'productIds' => $type === 'manual' ? $this->uniqueIds($data['productIds'] ?? []) : [],
        ];
        if ($type === 'automatic' && !$definition['rules']) {
            $this->fail('rules', 'Add at least one rule for an automatic collection.');
        }
        $sort = $data['productSort'] ?? ($type === 'manual' ? 'manual' : 'best_selling');

        return $this->previewPage($companyId, $definition, $sort, 1, (int) ($data['limit'] ?? 12));
    }

    /** Products of a saved collection, paged. */
    public function previewSaved(int $id, int $companyId, array $query): array
    {
        $collection = $this->find($id, $companyId);
        $sort = isset($query['sort']) && in_array($query['sort'], Collection::SORTS, true) ? $query['sort'] : ($collection->product_sort ?: 'manual');
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $page = max(1, (int) ($query['page'] ?? 1));

        return $this->previewPage($companyId, $this->engine->definitionFor($collection), $sort, $page, $limit);
    }

    private function previewPage(int $companyId, array $definition, string $sort, int $page, int $limit): array
    {
        $bestDays = null;
        foreach ($definition['rules'] as $r) {
            if ($r['field'] === 'best_sellers') {
                $bestDays = (int) $r['value']['days'];
            }
        }

        $total = $this->engine->productQuery($companyId, $definition)->count();
        $visibleTotal = $this->engine->productQuery($companyId, $definition, true)->count();

        $query = $this->engine->productQuery($companyId, $definition)
            ->with(['category:id,category_name', 'brand:id,brand_name', 'images'])
            ->selectRaw($this->engine->effectivePriceSql() . ' as effective_price')
            ->selectRaw($this->engine->unitsSoldSql($bestDays) . ' as units_sold');
        $this->engine->applySort($query, $sort, $definition, $companyId);
        $products = $query->forPage($page, $limit)->get();

        $positions = $definition['type'] === 'manual' ? array_flip($definition['productIds']) : null;
        $hidden = $total - $visibleTotal;

        return [
            'products' => $products->map(fn (Product $p) => $this->adminProduct($p, $positions))->values()->all(),
            'total' => $total,
            'visibleTotal' => $visibleTotal,
            'page' => $page,
            'limit' => $limit,
            'lastPage' => max(1, (int) ceil($total / $limit)),
            'hint' => $hidden > 0
                ? ($hidden === 1 ? '1 matching product is' : "{$hidden} matching products are") . ' hidden on the storefront (not published or not visible online).'
                : null,
        ];
    }

    private function adminProduct(Product $p, ?array $positions): array
    {
        $image = $p->images->firstWhere('is_primary', true)?->path ?? $p->images->first()?->path ?? $p->image;
        $out = [
            'id' => (int) $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'sku' => $p->sku,
            'image' => $image,
            'price' => (float) $p->price,
            'salePrice' => $p->sale_price !== null ? (float) $p->sale_price : null,
            'effectivePrice' => round((float) $p->effective_price, 2),
            'offerActive' => $p->isOfferActive(),
            'stock' => (int) $p->stock,
            'categoryId' => $p->category_id,
            'categoryName' => $p->category?->category_name,
            'brandId' => $p->brand_id,
            'brandName' => $p->brand?->brand_name,
            'tag' => $p->tag,
            'isBestSeller' => (bool) $p->is_best_seller,
            'isHotDeal' => (bool) $p->is_hot_deal,
            'isFeatured' => (bool) $p->is_featured,
            'published' => (bool) $p->published,
            'visibleOnline' => (bool) $p->visible_online,
            'visibleOnStorefront' => (bool) $p->published && (bool) $p->visible_online,
            'unitsSold' => (int) $p->units_sold,
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
        if ($positions !== null) {
            $out['position'] = isset($positions[$p->id]) ? $positions[$p->id] + 1 : null;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Storefront
    // ------------------------------------------------------------------

    public function storefrontList(int $companyId, bool $homepageOnly): array
    {
        return $this->repository->publishedByCompany($companyId, $homepageOnly)
            ->map(fn (Collection $c) => $this->storefrontSummary($c))
            ->values()->all();
    }

    public function findPublished(string $slugOrId, int $companyId): ?Collection
    {
        return $this->repository->findPublishedBySlugOrId($slugOrId, $companyId);
    }

    public function storefrontSummary(Collection $c, ?int $count = null): array
    {
        return [
            'id' => (int) $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'description' => $c->description,
            'image' => $c->image,
            'imageUrl' => CollectionMapper::imageUrl($c->image),
            'productCount' => $count ?? $this->engine->productQuery((int) $c->company_id, $this->engine->definitionFor($c), true)->count(),
            'showOnHomepage' => (bool) $c->show_on_homepage,
            'sortOrder' => (int) $c->sort_order,
            'productSort' => $c->product_sort ?: 'manual',
            'seoTitle' => $c->seo_title,
            'seoDescription' => $c->seo_description,
        ];
    }

    /**
     * Storefront products query (published + visible online) for a collection, sorted.
     * Returns [Builder, sortUsed]; the caller eager-loads / paginates / formats.
     */
    public function storefrontProductsQuery(Collection $collection, ?string $sort): array
    {
        $companyId = (int) $collection->company_id;
        $definition = $this->engine->definitionFor($collection);
        $sort = $sort && in_array($sort, Collection::SORTS, true) ? $sort : ($collection->product_sort ?: 'manual');
        $query = $this->engine->productQuery($companyId, $definition, true);
        $used = $this->engine->applySort($query, $sort, $definition, $companyId, true);

        return [$query, $used];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function find(int $id, int $companyId): Collection
    {
        $collection = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$collection) {
            throw new HttpException(404, 'Collection not found');
        }

        return $collection;
    }

    private function toDTO(Collection $c, bool $detail = false): CollectionDTO
    {
        $companyId = (int) $c->company_id;
        $manualIds = $c->type === 'automatic' ? [] : $this->repository->manualProductIds($c->id);
        $definition = $this->engine->definitionFor($c, $manualIds);

        $extra = [
            'productCount' => $this->engine->productQuery($companyId, $definition)->count(),
            'visibleProductCount' => $this->engine->productQuery($companyId, $definition, true)->count(),
        ];

        if ($detail) {
            $extra['productIds'] = $manualIds;
            $extra['manualProducts'] = [];
            if ($manualIds) {
                $extra['manualProducts'] = $this->previewPage($companyId, $definition, 'manual', 1, 1000)['products'];
                // productIds lists only products that still exist
                $extra['productIds'] = array_column($extra['manualProducts'], 'id');
            }
        }

        return $this->mapper->toDTO($c, $extra);
    }

    /** Ordered unique ids; 422 when any is not a product of this company. */
    private function assertProducts(array $ids, int $companyId): array
    {
        $ids = $this->uniqueIds($ids);
        if (!$ids) {
            return [];
        }
        $found = Product::where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $missing = array_values(array_diff($ids, $found));
        if ($missing) {
            $this->fail('productIds', 'Some products were not found: ' . implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ', ...' : '') . '.');
        }

        return $ids;
    }

    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
    }

    /**
     * Explicit slug: normalise and require it to be free. Empty: generate from the name (unique, -2, -3 ...).
     * On update with an empty slug the current slug is kept.
     */
    private function resolveSlug(?string $slug, string $name, int $companyId, ?int $excludeId = null, ?string $current = null): string
    {
        $slug = $slug !== null ? trim($slug) : '';
        if ($slug === '') {
            if ($current) {
                return $current;
            }
            $base = Str::limit(Str::slug($name), 180, '') ?: 'collection';
            $candidate = $base;
            $i = 2;
            while ($this->repository->slugExists($candidate, $companyId, $excludeId)) {
                $candidate = "{$base}-{$i}";
                $i++;
            }

            return $candidate;
        }

        $normalized = Str::limit(Str::slug($slug), 191, '');
        if ($normalized === '') {
            $this->fail('slug', 'The slug must contain letters or numbers.');
        }
        if ($this->repository->slugExists($normalized, $companyId, $excludeId)) {
            $this->fail('slug', "The slug \"{$normalized}\" is already used by another collection.");
        }

        return $normalized;
    }

    private function storeImage(mixed $image): ?string
    {
        if ($image instanceof UploadedFile) {
            return $image->store('collections', 'public');
        }

        return ($image === null || $image === '') ? null : (string) $image;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && !preg_match('#^(https?:)?//#i', $path) && str_starts_with($path, 'collections/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** 422 in the same shape as the collection FormRequests. */
    private function fail(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422));
    }
}
