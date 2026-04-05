<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\CreateProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UpdateProductStatusRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductService $productService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = $request->all();

        return $this->success($this->productService->list($companyId, $filters));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->productService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateProductRequest $request): JsonResponse
    {
        try {
            $companyId = (int) $request->attributes->get('auth_company_id');
            $validated = $request->validated();
            // Add image files from request
            $validated['image'] = $request->file('image') ?? [];

            \Log::debug('Product Store Request - All Data', [
                'companyId' => $companyId,
                'validated_keys' => array_keys($validated),
                'category_id' => $validated['category_id'] ?? 'NOT SET',
                'vendor_id' => $validated['vendor_id'] ?? 'NOT SET',
                'location_id' => $validated['location_id'] ?? 'NOT SET',
                'sale_price' => $validated['sale_price'] ?? 'NOT SET',
                'cost_price' => $validated['cost_price'] ?? 'NOT SET',
                'stock' => $validated['stock'] ?? 'NOT SET',
                'receipt_number' => $validated['receipt_number'] ?? 'NOT SET',
                'image_files' => is_array($validated['image']) ? count($validated['image']) : 0,
            ]);

            $dto = $this->productService->create($companyId, $validated);

            return $this->success(
                $dto->toArray(),
                'Product created successfully',
                201
            );
        } catch (\Exception $e) {
            \Log::error('Product Store Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        try {
            $companyId = (int) $request->attributes->get('auth_company_id');
            $validated = $request->validated();
            // Add image files from request
            $validated['image'] = $request->file('image') ?? [];
            $validated['keep_images'] = $request->input('keep_images') ?? null;

            \Log::debug('Product Update Request - All Data', [
                'product_id' => $id,
                'companyId' => $companyId,
                'validated_keys' => array_keys($validated),
                'category_id' => $validated['category_id'] ?? 'NOT SET',
                'vendor_id' => $validated['vendor_id'] ?? 'NOT SET',
                'location_id' => $validated['location_id'] ?? 'NOT SET',
                'sale_price' => $validated['sale_price'] ?? 'NOT SET',
                'cost_price' => $validated['cost_price'] ?? 'NOT SET',
                'stock' => $validated['stock'] ?? 'NOT SET',
                'barcode' => $validated['barcode'] ?? 'NOT SET',
                'image_files' => is_array($validated['image']) ? count($validated['image']) : 0,
            ]);

            $dto = $this->productService->update($id, $companyId, $validated);

            return $this->success($dto->toArray());
        } catch (\Exception $e) {
            \Log::error('Product Update Error', [
                'product_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function updateStatus(UpdateProductStatusRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->productService->updateStatus($id, $companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->productService->delete($id, $companyId);

        return $this->success(['message' => 'Product deleted successfully']);
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        if (!$companyId) {
            return $this->error('Company ID not found in context', 401);
        }

        try {
            $stats = $this->productService->getStats($companyId);
            return $this->success($stats, 'Statistics retrieved successfully');
        } catch (\Exception $e) {
            \Log::error('Product stats failed', ['message' => $e->getMessage()]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
