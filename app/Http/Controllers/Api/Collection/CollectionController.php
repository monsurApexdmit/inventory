<?php

namespace App\Http\Controllers\Api\Collection;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\CollectionStatusRequest;
use App\Http\Requests\Collection\PreviewCollectionRequest;
use App\Http\Requests\Collection\ReorderCollectionsRequest;
use App\Http\Requests\Collection\SetCollectionProductsRequest;
use App\Http\Requests\Collection\StoreCollectionRequest;
use App\Http\Requests\Collection\UpdateCollectionRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Collection\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CollectionService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->service->list($this->companyId($request), $request->only(['search', 'type', 'published', 'page', 'limit', 'per_page'])));
    }

    public function simple(Request $request): JsonResponse
    {
        return $this->success($this->service->simple($this->companyId($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->get($id, $this->companyId($request))->toArray());
    }

    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $dto = $this->service->create($this->companyId($request), $request->payload());

        return $this->success($dto->toArray(), 'Collection created', 201);
    }

    public function update(UpdateCollectionRequest $request, int $id): JsonResponse
    {
        $dto = $this->service->update($id, $this->companyId($request), $request->payload());

        return $this->success($dto->toArray(), 'Collection updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($id, $this->companyId($request));

        return $this->success(null, 'Collection deleted');
    }

    public function status(CollectionStatusRequest $request, int $id): JsonResponse
    {
        $value = $request->validated()['isPublished'] ?? null;
        $dto = $this->service->setStatus($id, $this->companyId($request), $value === null ? null : (bool) $value);

        return $this->success($dto->toArray(), $dto->isPublished ? 'Collection published' : 'Collection unpublished');
    }

    public function setProducts(SetCollectionProductsRequest $request, int $id): JsonResponse
    {
        $dto = $this->service->setProducts($id, $this->companyId($request), $request->validated()['productIds'] ?? []);

        return $this->success($dto->toArray(), 'Products saved');
    }

    public function reorder(ReorderCollectionsRequest $request): JsonResponse
    {
        $this->service->reorder($request->validated()['ids'], $this->companyId($request));

        return $this->success(null, 'Order saved');
    }

    public function preview(PreviewCollectionRequest $request): JsonResponse
    {
        return $this->success($this->service->previewDefinition($this->companyId($request), $request->payload()));
    }

    public function previewProducts(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->previewSaved($id, $this->companyId($request), $request->only(['page', 'limit', 'sort'])));
    }
}
