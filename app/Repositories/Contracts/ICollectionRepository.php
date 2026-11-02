<?php

namespace App\Repositories\Contracts;

use App\Models\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ICollectionRepository
{
    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator;

    public function simpleByCompany(int $companyId): array;

    public function findByIdAndCompany(int $id, int $companyId): ?Collection;

    /** Published collection by slug (or numeric id) for the storefront. */
    public function findPublishedBySlugOrId(string $slugOrId, int $companyId): ?Collection;

    /** @return \Illuminate\Support\Collection<int, Collection> */
    public function publishedByCompany(int $companyId, bool $homepageOnly = false): \Illuminate\Support\Collection;

    public function slugExists(string $slug, int $companyId, ?int $excludeId = null): bool;

    public function nextSortOrder(int $companyId): int;

    public function setSortOrders(array $ids, int $companyId): void;

    public function countOwned(array $ids, int $companyId): int;

    public function create(array $data): Collection;

    public function update(Collection $collection, array $data): Collection;

    public function delete(Collection $collection): void;

    /** Ordered product ids of a manual collection. */
    public function manualProductIds(int $collectionId): array;

    /** Replace the manual products with the given ordered ids (positions 1..n). */
    public function syncProducts(int $collectionId, array $productIds): void;
}
