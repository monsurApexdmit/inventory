<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Collection\CollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public collection endpoints under /api/store (company resolved by resolve_company). */
class StorefrontCollectionController extends Controller
{
    public function __construct(
        private readonly CollectionService $service,
        private readonly StorefrontController $storefront,
    ) {
    }

    // GET /api/store/collections?company_id=11[&homepage=1]
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->storefrontList($companyId, $request->boolean('homepage')),
        ]);
    }

    // GET /api/store/collections/{slugOrId}?company_id=11&sort=&page=1&per_page=20
    public function show(Request $request, string $slugOrId): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $collection = $this->service->findPublished($slugOrId, $companyId);
        if (!$collection) {
            return response()->json(['success' => false, 'message' => 'Collection not found'], 404);
        }

        $sort = $request->query('sort');
        $sort = is_string($sort) && $sort !== '' && $sort !== 'default' ? $sort : null;
        [$query, $usedSort] = $this->service->storefrontProductsQuery($collection, $sort);

        // Same eager loads as /api/store/products so formatProduct yields an identical shape.
        $query->with(['category', 'images', 'variants'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('orderItems', 'quantity');

        $perPage = max(1, min(100, (int) ($request->query('per_page') ?? $request->query('limit') ?? 20)));
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'collection' => $this->service->storefrontSummary($collection, $products->total()),
                'products' => $products->getCollection()->map(fn ($p) => $this->storefront->formatProduct($p))->values(),
            ],
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'sort' => $usedSort,
            ],
        ]);
    }
}
