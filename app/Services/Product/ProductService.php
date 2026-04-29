<?php

namespace App\Services\Product;

use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\ProductMapper;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantInventory;
use App\Repositories\Contracts\IProductRepository;
use App\Services\Barcode\BarcodeService;
use App\Services\Barcode\BarcodeServicePOS;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductService
{
    private readonly ProductMapper $mapper;
    private ?BarcodeService $barcodeService = null;
    private BarcodeServicePOS $barcodeServicePOS;

    public function __construct(private readonly IProductRepository $repository)
    {
        $this->mapper = new ProductMapper();
        // Lazy-load BarcodeService if available
        try {
            $this->barcodeService = app(BarcodeService::class);
        } catch (\Exception $e) {
            // BarcodeService not available, that's okay
            $this->barcodeService = null;
        }
        // Initialize BarcodeServicePOS for POS barcode generation
        $this->barcodeServicePOS = new BarcodeServicePOS();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($product) => $this->mapper->toDTO($product), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): ProductDTO
    {
        $product = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        return $this->mapper->toDTO($product);
    }

    /**
     * Get product model directly (for internal service use)
     */
    public function getModel(int $id, int $companyId): ?Product
    {
        return $this->repository->findByIdAndCompany($id, $companyId);
    }

    public function create(int $companyId, array $data): ProductDTO
    {
        // Validate foreign keys
        $this->validateForeignKeys($companyId, $data);

        // Map camelCase to snake_case
        $dbData = $this->mapInputToDb(array_merge($data, [
            'company_id' => $companyId,
        ]));
        $dbData['company_id'] = $companyId;

        $variants = $data['variants'] ?? [];
        $attributeIds = $data['attributes'] ?? [];
        $imageFiles = $data['image'] ?? [];
        if (!is_array($imageFiles)) {
            $imageFiles = [];
        }

        $productId = 0;

        DB::transaction(function () use (&$dbData, $variants, $attributeIds, $companyId, &$productId, $imageFiles) {
            $product = Product::create($dbData);
            $productId = $product->id;

            // Auto-generate barcode if not provided and service is available
            if (!$product->barcode && $this->barcodeService) {
                try {
                    $this->barcodeService->createProductBarcode($product);
                } catch (\Exception $e) {
                    Log::warning('Barcode generation failed', ['error' => $e->getMessage()]);
                    // Continue without barcode if generation fails
                }
            }

            if (!empty($variants)) {
                // Use only the product's specified location, not all company locations
                $locations = Location::where('company_id', $companyId)
                    ->where('id', $product->location_id)
                    ->get();

                foreach ($variants as $variantData) {
                    $variantDbData = $this->mapVariantInputToDb($variantData);
                    $variantDbData['product_id'] = $product->id;
                    $variant = ProductVariant::create($variantDbData);

                    // Auto-generate barcode if not provided
                    if (!$variant->barcode_code) {
                        try {
                            $barcodeCode = $this->barcodeServicePOS->generateBarcodeCode('VAR');
                            $variant->update([
                                'barcode_code' => $barcodeCode,
                                'barcode_format' => 'CODE128',
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('Variant barcode generation failed', ['error' => $e->getMessage()]);
                            // Continue without barcode if generation fails
                        }
                    }

                    // Create inventory record for the product's location with full stock
                    $variantStock = $variantData['stock'] ?? 0;
                    foreach ($locations as $location) {
                        VariantInventory::create([
                            'variant_id' => $variant->id,
                            'location_id' => $location->id,
                            'quantity' => $variantStock,
                        ]);
                    }
                }
            }

            if (!empty($attributeIds)) {
                $product->attributes()->sync($attributeIds);
            }

            // Sync product images
            if (!empty($imageFiles)) {
                $this->syncImages($product, $imageFiles);
            }
        });

        $product = $this->repository->findByIdAndCompany($productId, $companyId);
        $this->syncProductStock($product);

        return $this->mapper->toDTO($product);
    }

    public function update(int $id, int $companyId, array $data): ProductDTO
    {
        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        // Validate foreign keys only if they are present in data
        if (isset($data['category_id']) || isset($data['vendor_id']) || isset($data['location_id'])) {
            $this->validateForeignKeys($companyId, $data);
        }

        $imageFiles = $data['image'] ?? [];
        if (!is_array($imageFiles)) {
            $imageFiles = [];
        }

        $variants = $data['variants'] ?? [];
        $attributeIds = $data['attributes'] ?? [];

        $dbData = $this->mapInputToDb(array_merge($data, [
            'company_id' => $companyId,
            'ignore_product_id' => $product->id,
        ]));
        // Don't pass image array to fill()
        unset($dbData['image']);

        DB::transaction(function () use ($product, $dbData, $data, $imageFiles, $variants, $attributeIds, $companyId) {
            $product->fill($dbData)->save();

            // Handle attributes sync if provided
            if (!empty($attributeIds)) {
                $product->attributes()->sync($attributeIds);
            }

            // Handle variants - if new variants provided, replace old ones
            if (!empty($variants)) {
                // Delete old variants
                $product->variants()->delete();

                // Create new variants
                // Use only the product's specified location, not all company locations
                $locations = Location::where('company_id', $companyId)
                    ->where('id', $product->location_id)
                    ->get();

                foreach ($variants as $variantData) {
                    $variantDbData = $this->mapVariantInputToDb($variantData);
                    $variantDbData['product_id'] = $product->id;
                    $variant = ProductVariant::create($variantDbData);

                    // Auto-generate barcode if not provided
                    if (!$variant->barcode_code) {
                        try {
                            $barcodeCode = $this->barcodeServicePOS->generateBarcodeCode('VAR');
                            $variant->update([
                                'barcode_code' => $barcodeCode,
                                'barcode_format' => 'CODE128',
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('Variant barcode generation failed', ['error' => $e->getMessage()]);
                            // Continue without barcode if generation fails
                        }
                    }

                    // Create inventory record for the product's location with full stock
                    $variantStock = $variantData['stock'] ?? 0;
                    foreach ($locations as $location) {
                        VariantInventory::create([
                            'variant_id' => $variant->id,
                            'location_id' => $location->id,
                            'quantity' => $variantStock,
                        ]);
                    }
                }
            }

            // Handle images with keep_images support
            $keepPaths = $data['keep_images'] ?? null;
            if ($keepPaths !== null) {
                // Delete images not in keep list
                foreach ($product->images as $img) {
                    if (!in_array($img->path, (array) $keepPaths)) {
                        Storage::disk('public')->delete($img->path);
                        $img->delete();
                    }
                }
                // Add new files
                if (!empty($imageFiles)) {
                    $this->syncImages($product, $imageFiles);
                }
                // Update primary image
                $firstImage = $product->images()->orderBy('position')->first();
                $product->update(['image' => $firstImage ? $firstImage->path : null]);
            } elseif (!empty($imageFiles)) {
                $this->deleteProductImages($product);
                $this->syncImages($product, $imageFiles);
            }
        });

        $this->syncProductStock($product->refresh());

        return $this->get($id, $companyId);
    }

    public function updateStatus(int $id, int $companyId, array $data): ProductDTO
    {
        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        $product->update(['published' => $data['published']]);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        // Delete associated images before deleting product
        $this->deleteProductImages($product);

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function validateForeignKeys(int $companyId, array $data): void
    {
        $categoryId = $data['category_id'] ?? $data['categoryId'] ?? null;
        if ($categoryId) {
            $category = Category::where('company_id', $companyId)
                ->where('id', $categoryId)
                ->exists();

            if (!$category) {
                throw new HttpException(400, 'Category not found or does not belong to this company');
            }
        }

        $vendorId = $data['vendor_id'] ?? $data['vendorId'] ?? null;
        if ($vendorId) {
            $vendor = User::where('id', $vendorId)->exists();

            if (!$vendor) {
                throw new HttpException(400, 'Vendor not found');
            }
        }

        $locationId = $data['location_id'] ?? $data['locationId'] ?? null;
        if ($locationId) {
            $location = Location::where('company_id', $companyId)
                ->where('id', $locationId)
                ->exists();

            if (!$location) {
                throw new HttpException(400, 'Location not found or does not belong to this company');
            }
        }
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
            $dbData['slug'] = $this->generateUniqueSlug(
                companyId: (int) ($data['company_id'] ?? 0),
                name: (string) $data['name'],
                ignoreProductId: $data['ignore_product_id'] ?? null,
            );
        }
        if (isset($data['description'])) {
            $dbData['description'] = $data['description'];
        }

        // Accept both camelCase and snake_case
        if (isset($data['categoryId'])) {
            $dbData['category_id'] = $data['categoryId'];
        } elseif (isset($data['category_id'])) {
            $dbData['category_id'] = $data['category_id'];
        }

        if (isset($data['vendorId'])) {
            $dbData['vendor_id'] = $data['vendorId'];
        } elseif (isset($data['vendor_id'])) {
            $dbData['vendor_id'] = $data['vendor_id'];
        }

        if (isset($data['locationId'])) {
            $dbData['location_id'] = $data['locationId'];
        } elseif (isset($data['location_id'])) {
            $dbData['location_id'] = $data['location_id'];
        }

        if (isset($data['price'])) {
            $dbData['price'] = $data['price'];
        }

        if (isset($data['salePrice'])) {
            $dbData['sale_price'] = $data['salePrice'];
        } elseif (isset($data['sale_price'])) {
            $dbData['sale_price'] = $data['sale_price'];
        }

        if (isset($data['costPrice'])) {
            $dbData['cost_price'] = $data['costPrice'];
        } elseif (isset($data['cost_price'])) {
            $dbData['cost_price'] = $data['cost_price'];
        }

        // Accept both camelCase and snake_case for profit_margin
        if (isset($data['profitMargin'])) {
            $dbData['profit_margin'] = $data['profitMargin'];
        } elseif (isset($data['profit_margin'])) {
            $dbData['profit_margin'] = $data['profit_margin'];
        }

        // Accept both camelCase and snake_case for margin_type
        if (isset($data['marginType'])) {
            $dbData['margin_type'] = $data['marginType'];
        } elseif (isset($data['margin_type'])) {
            $dbData['margin_type'] = $data['margin_type'];
        }

        if (isset($data['stock'])) {
            $dbData['stock'] = $data['stock'];
        }
        if (isset($data['sku'])) {
            $dbData['sku'] = $data['sku'];
        }
        if (isset($data['barcode'])) {
            $dbData['barcode'] = $data['barcode'];
        }
        if (isset($data['published'])) {
            $dbData['published'] = $data['published'];
        }

        if (isset($data['receiptNumber'])) {
            $dbData['receipt_number'] = $data['receiptNumber'];
        } elseif (isset($data['receipt_number'])) {
            $dbData['receipt_number'] = $data['receipt_number'];
        }

        if (isset($data['is_hot_deal'])) {
            $dbData['is_hot_deal'] = filter_var($data['is_hot_deal'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_best_seller'])) {
            $dbData['is_best_seller'] = filter_var($data['is_best_seller'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_featured'])) {
            $dbData['is_featured'] = filter_var($data['is_featured'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('deal_label', $data)) {
            $dbData['deal_label'] = $data['deal_label'] !== '' ? $data['deal_label'] : null;
        }

        // Skip image if it's an array (file uploads) - will be synced separately
        if (isset($data['image']) && !is_array($data['image'])) {
            $dbData['image'] = $data['image'];
        }

        return $dbData;
    }

    private function generateUniqueSlug(int $companyId, string $name, ?int $ignoreProductId = null): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = 'product';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->slugExists($companyId, $slug, $ignoreProductId)) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function slugExists(int $companyId, string $slug, ?int $ignoreProductId = null): bool
    {
        $query = Product::withTrashed()
            ->where('company_id', $companyId)
            ->where('slug', $slug);

        if ($ignoreProductId) {
            $query->where('id', '<>', $ignoreProductId);
        }

        return $query->exists();
    }

    private function mapVariantInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['attributes'])) {
            $dbData['attributes'] = is_string($data['attributes']) ? $data['attributes'] : json_encode($data['attributes']);
        }
        if (isset($data['price'])) {
            $dbData['price'] = $data['price'];
        }

        // Accept both camelCase and snake_case for sale_price
        if (isset($data['salePrice'])) {
            $dbData['sale_price'] = $data['salePrice'];
        } elseif (isset($data['sale_price'])) {
            $dbData['sale_price'] = $data['sale_price'];
        }

        // Accept both camelCase and snake_case for cost_price
        if (isset($data['costPrice'])) {
            $dbData['cost_price'] = $data['costPrice'];
        } elseif (isset($data['cost_price'])) {
            $dbData['cost_price'] = $data['cost_price'];
        }

        // Accept both camelCase and snake_case for profit_margin
        if (isset($data['profitMargin'])) {
            $dbData['profit_margin'] = $data['profitMargin'];
        } elseif (isset($data['profit_margin'])) {
            $dbData['profit_margin'] = $data['profit_margin'];
        }

        // Accept both camelCase and snake_case for margin_type
        if (isset($data['marginType'])) {
            $dbData['margin_type'] = $data['marginType'];
        } elseif (isset($data['margin_type'])) {
            $dbData['margin_type'] = $data['margin_type'];
        }

        if (isset($data['stock'])) {
            $dbData['stock'] = $data['stock'];
        }
        if (isset($data['sku'])) {
            $dbData['sku'] = $data['sku'];
        }
        if (isset($data['barcode'])) {
            $dbData['barcode'] = $data['barcode'];
        }

        return $dbData;
    }

    private function syncProductStock(Product $product): void
    {
        if ($product->variants()->exists()) {
            $totalStock = $product->variants()->sum('stock');
            $product->update(['stock' => $totalStock]);
        }
    }

    private function syncImages(Product $product, array $imageFiles): void
    {
        Log::debug('syncImages called', [
            'product_id' => $product->id,
            'file_count' => count($imageFiles),
        ]);

        $primaryPath = null;

        foreach ($imageFiles as $index => $file) {
            try {
                Log::debug('Storing image', [
                    'index' => $index,
                    'file_name' => $file->getClientOriginalName(),
                ]);

                $path = $file->store('products', 'public');

                if ($index === 0) {
                    $primaryPath = $path;
                }

                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'position' => $index,
                    'is_primary' => $index === 0,
                ]);

                Log::debug('Image stored successfully', [
                    'path' => $path,
                    'is_primary' => $index === 0,
                ]);
            } catch (\Exception $e) {
                Log::error('Image storage failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Sync products.image to primary image path
        if ($primaryPath) {
            $product->update(['image' => $primaryPath]);
        }
    }

    private function deleteProductImages(Product $product): void
    {
        foreach ($product->images as $img) {
            Storage::disk('public')->delete($img->path);
            $img->delete();
        }
        $product->update(['image' => null]);
    }
}
