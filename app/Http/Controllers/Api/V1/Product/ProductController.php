<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\CreateProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UpdateProductStatusRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Product\ProductService;
use App\Services\Barcode\BarcodeService;
use App\Services\Barcode\BarcodeServicePOS;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductController extends Controller
{
    use ApiResponse;

    private ?BarcodeService $barcodeService = null;

    public function __construct(private readonly ProductService $productService)
    {
        // Lazy-load BarcodeService if available
        try {
            $this->barcodeService = app(BarcodeService::class);
        } catch (\Exception $e) {
            $this->barcodeService = null;
        }
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

    /**
     * Find product by barcode
     * POST /api/v1/products/barcode/search
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function findByBarcode(Request $request): JsonResponse
    {
        if (!$this->barcodeService) {
            return $this->error('Barcode service is not available', 503);
        }

        $companyId = (int) $request->attributes->get('auth_company_id');
        $barcode = trim($request->input('barcode', ''));

        if (empty($barcode)) {
            return $this->error('Barcode is required', 400);
        }

        try {
            $product = $this->barcodeService->findProductByBarcode($barcode, $companyId);

            if (!$product) {
                return $this->error('Product not found for barcode: ' . $barcode, 404);
            }

            return $this->success(
                $this->productService->get($product->id, $companyId)->toArray(),
                'Product found'
            );
        } catch (\Exception $e) {
            \Log::error('Barcode search failed', [
                'barcode' => $barcode,
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to search by barcode', 500);
        }
    }

    /**
     * Regenerate barcode for a product
     * POST /api/v1/products/{id}/barcode/regenerate
     *
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     */
    public function regenerateBarcode(Request $request, int $id): JsonResponse
    {
        if (!$this->barcodeService) {
            return $this->error('Barcode service is not available', 503);
        }

        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $product = $this->productService->getModel($id, $companyId);

            if (!$product) {
                return $this->error('Product not found', 404);
            }

            $barcodeNumber = $this->barcodeService->regenerateProductBarcode($product);

            return $this->success(
                [
                    'barcode_number' => $barcodeNumber,
                    'barcode_image_path' => $product->barcode_image_path,
                ],
                'Barcode regenerated successfully'
            );
        } catch (\Exception $e) {
            \Log::error('Barcode regeneration failed', [
                'product_id' => $id,
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to regenerate barcode', 500);
        }
    }

    /**
     * Get product barcode details
     * GET /api/v1/products/{id}/barcode
     *
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     */
    public function getBarcode(Request $request, int $id): JsonResponse
    {
        if (!$this->barcodeService) {
            return $this->error('Barcode service is not available', 503);
        }

        $companyId = (int) $request->attributes->get('auth_company_id');

        try {
            $product = $this->productService->getModel($id, $companyId);

            if (!$product) {
                return $this->error('Product not found', 404);
            }

            $barcodeData = $this->barcodeService->getBarcodeData($product);

            return $this->success($barcodeData, 'Barcode data retrieved');
        } catch (\Exception $e) {
            \Log::error('Barcode retrieval failed', [
                'product_id' => $id,
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to retrieve barcode', 500);
        }
    }

    /**
     * Bulk generate barcodes for products without barcodes
     * POST /api/v1/products/barcode/bulk-generate
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkGenerateBarcodes(Request $request): JsonResponse
    {
        if (!$this->barcodeService) {
            return $this->error('Barcode service is not available', 503);
        }

        $companyId = (int) $request->attributes->get('auth_company_id');
        $productIds = $request->input('product_ids', []);

        if (empty($productIds) || !is_array($productIds)) {
            return $this->error('product_ids array is required', 400);
        }

        try {
            $result = $this->barcodeService->bulkGenerateBarcodes($productIds);

            return $this->success($result, 'Barcode generation completed');
        } catch (\Exception $e) {
            \Log::error('Bulk barcode generation failed', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to generate barcodes', 500);
        }
    }

    /**
     * POS: Find product or variant by barcode code
     * Used for barcode scanning in POS system
     */
    public function findByBarcodeCode(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $barcodeCode = $request->input('barcode_code');

        if (!$barcodeCode) {
            return $this->error('Barcode code is required', 400);
        }

        try {
            $barcodeService = new BarcodeServicePOS();
            $result = $barcodeService->findByBarcode($barcodeCode, $companyId);

            if (!$result) {
                return $this->error('Barcode not found', 404);
            }

            return $this->success($result, 'Barcode found');
        } catch (\Exception $e) {
            \Log::error('Barcode search failed', [
                'barcode_code' => $barcodeCode,
                'error' => $e->getMessage()
            ]);
            return $this->error('Failed to search barcode', 500);
        }
    }

    /**
     * POS: Auto-generate missing barcodes for products and variants
     * Generates barcodes for all products/variants without barcode_code
     */
    public function generateMissingBarcodes(Request $request): JsonResponse
    {
        try {
            $barcodeService = new BarcodeServicePOS();
            $result = $barcodeService->generateMissingBarcodes();

            return $this->success($result, 'Barcodes generated successfully');
        } catch (\Exception $e) {
            \Log::error('Barcode generation failed', [
                'error' => $e->getMessage()
            ]);
            return $this->error('Failed to generate barcodes', 500);
        }
    }

    /**
     * POS: Get barcode coverage statistics
     * Shows how many products/variants have barcodes
     */
    public function getBarcodeStatistics(Request $request): JsonResponse
    {
        try {
            $barcodeService = new BarcodeServicePOS();
            $stats = $barcodeService->getStatistics();

            return $this->success($stats, 'Barcode statistics retrieved');
        } catch (\Exception $e) {
            \Log::error('Barcode statistics retrieval failed', [
                'error' => $e->getMessage()
            ]);
            return $this->error('Failed to retrieve statistics', 500);
        }
    }
}
