<?php

namespace App\Services\Product;

use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\ProductMapper;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use App\Models\User;
use App\Models\Vendor;
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
    private const MAX_IMAGES = 6;

    private readonly ProductMapper $mapper;
    private ?BarcodeService $barcodeService = null;
    private BarcodeServicePOS $barcodeServicePOS;
    private ProductStockService $stockService;

    public function __construct(private readonly IProductRepository $repository)
    {
        $this->mapper = new ProductMapper();
        $this->stockService = new ProductStockService();
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

    /**
     * Filtered, stock-aware product list.
     *
     * Filters: search, categoryId|category_id, brandId (id|none), supplierId|vendor_id (id|none),
     * location_id (legacy home-location filter), locationId (warehouse that stock/state/tabs/sort
     * are evaluated against), pos=1, published, tab (all|ok|low|out|hidden|draft),
     * sort (recent|name|price-asc|price-desc|stock-asc|stock-desc), page, limit.
     * Tab counts follow every active filter except the tab itself.
     */
    public function list(int $companyId, array $filters): array
    {
        $warehouse = isset($filters['locationId']) && $filters['locationId'] !== '' ? (int) $filters['locationId'] : null;
        $tab = (string) ($filters['tab'] ?? $filters['stockState'] ?? 'all');
        $sort = (string) ($filters['sort'] ?? '');
        $limit = max(1, min((int) ($filters['limit'] ?? 15), 1000));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $lean = $this->repository->filteredQuery($companyId, $filters)
            ->get(['id', 'name', 'price', 'sale_price', 'stock', 'reorder_point', 'is_bundle', 'location_id', 'published', 'visible_online', 'created_at']);
        $computed = $this->stockService->compute($lean, $companyId, $warehouse);

        $hidden = fn ($p) => !$p->published || !$p->visible_online;
        $counts = ['all' => $lean->count(), 'ok' => 0, 'low' => 0, 'out' => 0, 'hidden' => 0, 'draft' => 0];
        foreach ($lean as $p) {
            $counts[$computed[$p->id]['stockState']]++;
            if ($hidden($p)) {
                $counts['hidden']++;
            }
            if (!$p->published) {
                $counts['draft']++;
            }
        }

        $rows = $lean->filter(function ($p) use ($tab, $computed, $hidden) {
            return match ($tab) {
                'ok', 'low', 'out' => $computed[$p->id]['stockState'] === $tab,
                'hidden' => $hidden($p),
                'draft' => !$p->published,
                default => true,
            };
        });

        $price = fn ($p) => (float) ((float) $p->sale_price > 0 ? $p->sale_price : $p->price);
        $rows = match ($sort) {
            'name' => $rows->sortBy(fn ($p) => mb_strtolower($p->name)),
            'price-asc' => $rows->sortBy($price),
            'price-desc' => $rows->sortByDesc($price),
            'stock-asc' => $rows->sortBy(fn ($p) => $computed[$p->id]['stock']),
            'stock-desc' => $rows->sortByDesc(fn ($p) => $computed[$p->id]['stock']),
            'recent' => $rows->sortByDesc('id'),
            default => $rows->sortBy('id'),
        };
        $rows = $rows->values();

        $total = $rows->count();
        $pageIds = $rows->slice(($page - 1) * $limit, $limit)->pluck('id')->all();
        $models = $pageIds
            ? $this->repository->filteredQuery($companyId, [], true)->whereIn('id', $pageIds)->get()->keyBy('id')
            : collect();

        $data = [];
        foreach ($pageIds as $id) {
            if ($m = $models->get($id)) {
                $data[] = $this->mapper->toDTO($m, $computed[$id]);
            }
        }

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $limit)),
            'tabCounts' => $counts,
        ];
    }

    /** Ids of every product matching the list filters (+ tab), used by bulk actions. */
    public function matchingIds(int $companyId, array $filters): array
    {
        $filters['limit'] = 1000000;
        $filters['page'] = 1;
        $lean = $this->repository->filteredQuery($companyId, $filters)->get(['id', 'stock', 'reorder_point', 'is_bundle', 'location_id', 'published', 'visible_online']);
        $warehouse = isset($filters['locationId']) && $filters['locationId'] !== '' ? (int) $filters['locationId'] : null;
        $computed = $this->stockService->compute($lean, $companyId, $warehouse);
        $tab = (string) ($filters['tab'] ?? 'all');

        return $lean->filter(fn ($p) => match ($tab) {
            'ok', 'low', 'out' => $computed[$p->id]['stockState'] === $tab,
            'hidden' => !$p->published || !$p->visible_online,
            'draft' => !$p->published,
            default => true,
        })->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
    }

    public function get(int $id, int $companyId): ProductDTO
    {
        $product = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        $computed = $this->stockService->compute(collect([$product]), $companyId, null);

        return $this->mapper->toDTO($product, $computed[$product->id] ?? null);
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
        $this->assertProductRules($companyId, $data, null);

        // Reject an invalid supplier payment before the product is created
        if (($data['supplier_payment_status'] ?? null) === 'partial') {
            $qty = !empty($data['variants'])
                ? (int) collect($data['variants'])->sum(fn($v) => (int) ($v['stock'] ?? 0))
                : (int) ($data['stock'] ?? 0);
            $supplierTotal = (float) ($data['cost_price'] ?? 0) * $qty;
            if ((float) ($data['supplier_paid_amount'] ?? 0) > $supplierTotal) {
                throw new HttpException(422, 'Paid amount cannot exceed the product cost total');
            }
        }

        // Map camelCase to snake_case
        $dbData = $this->mapInputToDb(array_merge($data, [
            'company_id' => $companyId,
        ]));
        $dbData['company_id'] = $companyId;

        // New products start with the company's default low-stock level only when none was given (0 = alert off)
        if (!array_key_exists('reorder_point', $dbData) && ($default = \App\Support\DocumentNumbers::lowStockDefault($companyId))) {
            $dbData['reorder_point'] = $default;
        }

        $variants = $data['variants'] ?? [];
        $attributeIds = $data['attributes'] ?? [];
        $bundleItems = $data['bundleItems'] ?? [];
        $imageFiles = $data['image'] ?? [];
        if (!is_array($imageFiles)) {
            $imageFiles = [];
        }

        $productId = 0;

        DB::transaction(function () use (&$dbData, $variants, $attributeIds, $bundleItems, $companyId, &$productId, $imageFiles) {
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

            // Bundle items
            if (!empty($bundleItems)) {
                $product->bundleItems()->delete();
                foreach ($bundleItems as $bi) {
                    ProductBundleItem::create([
                        'bundle_product_id' => $product->id,
                        'product_id'        => $bi['productId'],
                        'variant_id'        => $bi['variantId'] ?? null,
                        'quantity'          => $bi['quantity'] ?? 1,
                    ]);
                }
            }

            // Sync product images
            if (!empty($imageFiles)) {
                $this->syncImages($product, $imageFiles);
            }
        });

        $product = $this->repository->findByIdAndCompany($productId, $companyId);
        $this->syncProductStock($product);
        if ($product->is_bundle) {
            $this->syncBundleStock($product);
        }

        $this->recordSupplierPurchase($product, $companyId, $data);

        return $this->get($productId, $companyId);
    }

    /**
     * When a product is added with a supplier, record what is owed to that supplier as a
     * received purchase order (cost price x opening stock) and apply the chosen payment.
     * Stock is already set by product creation, so the PO is created as fully received
     * and does not touch inventory.
     */
    private function recordSupplierPurchase(Product $product, int $companyId, array $data): void
    {
        if (!$product->vendor_id) {
            return;
        }

        $costPrice = (float) ($product->cost_price ?? 0);
        $quantity = (int) ($data['stock'] ?? 0);
        if (!empty($data['variants'])) {
            $quantity = (int) collect($data['variants'])->sum(fn($v) => (int) ($v['stock'] ?? 0));
        }
        $total = $costPrice * $quantity;
        if ($total <= 0) {
            return;
        }

        $status = $data['supplier_payment_status'] ?? 'due';
        $paid = match ($status) {
            'paid' => $total,
            'partial' => (float) ($data['supplier_paid_amount'] ?? 0),
            default => 0.0,
        };
        if ($paid > $total) {
            throw new HttpException(422, 'Paid amount cannot exceed the product cost total');
        }

        DB::transaction(function () use ($product, $companyId, $costPrice, $quantity, $total, $paid, $data) {
            $poRepo = app(IPurchaseOrderRepository::class);
            $po = $poRepo->create([
                'company_id'    => $companyId,
                'vendor_id'     => $product->vendor_id,
                'location_id'   => $product->location_id,
                'po_number'     => $poRepo->nextPoNumber($companyId),
                'status'        => 'received',
                'notes'         => 'Auto-created from product: ' . $product->name,
                'total_amount'  => $total,
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id'        => $product->id,
                'variant_id'        => null,
                'quantity_ordered'  => $quantity,
                'quantity_received' => $quantity,
                'unit_cost'         => $costPrice,
                'subtotal'          => $total,
            ]);

            if ($paid > 0) {
                $po->payments()->create([
                    'company_id'     => $companyId,
                    'direction'      => 'out',
                    'amount'         => $paid,
                    'payment_method' => $data['supplier_payment_method'] ?? 'cash',
                    'payment_date'   => $data['supplier_payment_date'] ?? now()->toDateString(),
                    'notes'          => 'Payment at product creation',
                ]);
            }
            $po->recalculate();
        });
    }

    public function update(int $id, int $companyId, array $data): ProductDTO
    {
        $product = Product::where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        $this->assertProductRules($companyId, $data, $product);

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
        $bundleItems = $data['bundleItems'] ?? null;

        $dbData = $this->mapInputToDb(array_merge($data, [
            'company_id' => $companyId,
            'ignore_product_id' => $product->id,
        ]));
        // Don't pass image array to fill()
        unset($dbData['image']);

        DB::transaction(function () use ($product, $dbData, $data, $imageFiles, $variants, $attributeIds, $bundleItems, $companyId) {
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

            // Handle bundle items
            if ($bundleItems !== null) {
                $product->bundleItems()->delete();
                foreach ($bundleItems as $bi) {
                    ProductBundleItem::create([
                        'bundle_product_id' => $product->id,
                        'product_id'        => $bi['productId'],
                        'variant_id'        => $bi['variantId'] ?? null,
                        'quantity'          => $bi['quantity'] ?? 1,
                    ]);
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

        $refreshed = $product->refresh();
        $this->syncProductStock($refreshed);
        if ($refreshed->is_bundle) {
            $this->syncBundleStock($refreshed);
        }

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

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /**
     * Business-rule validation shared by create and update (422 with a readable message).
     * $existing is null on create.
     */
    private function assertProductRules(int $companyId, array $data, ?Product $existing): void
    {
        $creating = $existing === null;
        $hasVariants = !empty($data['variants']);

        // Selling price must be > 0
        $touchesPrice = array_key_exists('sale_price', $data) || array_key_exists('price', $data);
        if (($creating && !$hasVariants) || (!$creating && $touchesPrice)) {
            $sale = array_key_exists('sale_price', $data) ? (float) $data['sale_price'] : (float) ($existing->sale_price ?? 0);
            $price = array_key_exists('price', $data) ? (float) $data['price'] : (float) ($existing->price ?? 0);
            if (($sale > 0 ? $sale : $price) <= 0) {
                throw new HttpException(422, 'Selling price must be greater than 0.');
            }
        }

        if (isset($data['cost_price']) && (float) $data['cost_price'] < 0) {
            throw new HttpException(422, 'Cost price cannot be negative.');
        }

        // Percentage offers cannot exceed 100%
        $offerType = array_key_exists('offer_type', $data) ? $data['offer_type'] : ($existing->offer_type ?? null);
        if (isset($data['offer_price']) && $offerType === 'percentage' && (float) $data['offer_price'] > 100) {
            throw new HttpException(422, 'A percentage offer cannot be more than 100%.');
        }
        foreach ((array) ($data['variants'] ?? []) as $v) {
            $vType = $v['offer_type'] ?? $v['offerType'] ?? $offerType;
            $vOffer = $v['offer_price'] ?? $v['offerPrice'] ?? null;
            if ($vOffer !== null && $vOffer !== '' && $vType === 'percentage' && (float) $vOffer > 100) {
                throw new HttpException(422, "Variant '" . ($v['name'] ?? '') . "': a percentage offer cannot be more than 100%.");
            }
            $vPrice = $v['sale_price'] ?? $v['salePrice'] ?? $v['price'] ?? null;
            if ($vPrice !== null && $vPrice !== '' && (float) $vPrice < 0) {
                throw new HttpException(422, "Variant '" . ($v['name'] ?? '') . "': price cannot be negative.");
            }
            $vWs = $v['wholesale_price'] ?? $v['wholesalePrice'] ?? null;
            if ($vWs !== null && $vWs !== '' && (float) $vWs < 0) {
                throw new HttpException(422, "Variant '" . ($v['name'] ?? '') . "': wholesale price cannot be negative.");
            }
            if (isset($v['cost_price']) && (float) $v['cost_price'] < 0) {
                throw new HttpException(422, "Variant '" . ($v['name'] ?? '') . "': cost price cannot be negative.");
            }
        }

        // Image count (new uploads + kept images)
        $files = is_array($data['image'] ?? null) ? count($data['image']) : 0;
        $kept = ($existing && isset($data['keep_images']) && is_array($data['keep_images'])) ? count($data['keep_images']) : 0;
        if ($files + $kept > self::MAX_IMAGES) {
            throw new HttpException(422, 'A product can have at most ' . self::MAX_IMAGES . ' images.');
        }

        $this->assertUniqueCodes($companyId, $data, $existing?->id);
    }

    /** Duplicate SKU / barcode checks across products and variants (in payload and in the DB). */
    private function assertUniqueCodes(int $companyId, array $data, ?int $ignoreProductId): void
    {
        $skus = [];
        $barcodes = [];
        $add = function (array &$bag, $value, string $label) {
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                return;
            }
            $key = mb_strtolower($value);
            if (isset($bag[$key])) {
                throw new HttpException(422, "Duplicate " . $bag['__kind'] . " '{$value}' ({$label} and {$bag[$key]}).");
            }
            $bag[$key] = $label;
        };
        $skus['__kind'] = 'SKU';
        $barcodes['__kind'] = 'barcode';

        $add($skus, $data['sku'] ?? null, 'product');
        $add($barcodes, $data['barcode'] ?? null, 'product');
        foreach ((array) ($data['variants'] ?? []) as $v) {
            $label = "variant '" . ($v['name'] ?? '') . "'";
            $add($skus, $v['sku'] ?? null, $label);
            $add($barcodes, $v['barcode'] ?? null, $label);
        }
        unset($skus['__kind'], $barcodes['__kind']);

        foreach (array_keys($skus) as $sku) {
            if ($other = $this->findCodeOwner($companyId, 'sku', $sku, $ignoreProductId)) {
                throw new HttpException(422, "SKU '{$sku}' is already used by {$other}.");
            }
        }
        foreach (array_keys($barcodes) as $code) {
            if ($other = $this->findCodeOwner($companyId, 'barcode', $code, $ignoreProductId)) {
                throw new HttpException(422, "Barcode '{$code}' is already used by {$other}.");
            }
        }
    }

    /** Returns a human label of the product/variant that already owns this (case-insensitive) code. */
    public function findCodeOwner(int $companyId, string $column, string $value, ?int $ignoreProductId = null): ?string
    {
        $lower = mb_strtolower($value);
        $p = Product::where('company_id', $companyId)->whereRaw("LOWER({$column}) = ?", [$lower])
            ->when($ignoreProductId, fn ($q) => $q->where('id', '<>', $ignoreProductId))
            ->first(['id', 'name']);
        if ($p) {
            return "product '{$p->name}'";
        }

        $v = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->where('products.company_id', $companyId)
            ->where('product_variants.name', '<>', 'Default')
            ->whereRaw("LOWER(product_variants.{$column}) = ?", [$lower])
            ->when($ignoreProductId, fn ($q) => $q->where('products.id', '<>', $ignoreProductId))
            ->first(['products.name as product_name', 'product_variants.name as variant_name']);

        return $v ? "variant '{$v->variant_name}' of '{$v->product_name}'" : null;
    }

    // ---------------------------------------------------------------------
    // Bulk actions
    // ---------------------------------------------------------------------

    /**
     * Reprice products by +/- percent: price, sale price, flat offer price, wholesale price,
     * bundle override and every variant. Percentage offers (a % value) are left alone.
     *
     * @param array{ids?:array,filter?:array,percent:float|int|string} $data
     * @return array{count:int, variantsUpdated:int}
     */
    public function bulkPrice(int $companyId, array $data): array
    {
        $percent = (float) $data['percent'];
        if ($percent <= -100) {
            throw new HttpException(422, 'Percent must be greater than -100.');
        }

        $ids = !empty($data['ids'])
            ? Product::where('company_id', $companyId)->whereIn('id', array_map('intval', $data['ids']))->pluck('id')->all()
            : $this->matchingIds($companyId, (array) ($data['filter'] ?? []));
        if (empty($ids)) {
            return ['count' => 0, 'variantsUpdated' => 0];
        }

        $f = 1 + $percent / 100;
        $r = fn ($v) => $v === null ? null : round((float) $v * $f, 2);
        $variantsUpdated = 0;

        DB::transaction(function () use ($ids, $f, $r, &$variantsUpdated) {
            foreach (array_chunk($ids, 200) as $chunk) {
                foreach (Product::whereIn('id', $chunk)->get() as $p) {
                    $upd = ['price' => $r($p->price), 'sale_price' => $r($p->sale_price)];
                    if ($p->offer_price !== null && $p->offer_type !== 'percentage') {
                        $upd['offer_price'] = $r($p->offer_price);
                    }
                    if ($p->wholesale_price !== null) {
                        $upd['wholesale_price'] = $r($p->wholesale_price);
                    }
                    if ($p->bundle_price_override !== null) {
                        $upd['bundle_price_override'] = $r($p->bundle_price_override);
                    }
                    $p->update($upd);

                    foreach (ProductVariant::where('product_id', $p->id)->get() as $v) {
                        $vu = ['price' => $r($v->price), 'sale_price' => $r($v->sale_price)];
                        if ($v->offer_price !== null && $v->offer_type !== 'percentage') {
                            $vu['offer_price'] = $r($v->offer_price);
                        }
                        $v->update($vu);
                        $variantsUpdated++;
                    }
                }
            }
        });

        return ['count' => count($ids), 'variantsUpdated' => $variantsUpdated];
    }

    /**
     * Quick / bulk stock adjustment through the stock-adjustment service (movement history is kept).
     *
     * @param array{items:array<int,array{productId:int,variantId?:?int,locationId?:?int,delta:int,reason?:string}>,reason?:string} $data
     * @return array{adjusted:int, failed:array, results:array}
     */
    public function adjustStock(int $companyId, array $data): array
    {
        $adjuster = app(\App\Services\StockAdjustment\StockAdjustmentService::class);
        $locations = app(\App\Services\Inventory\LocationStockService::class);
        $defaultReason = trim((string) ($data['reason'] ?? '')) ?: 'Quick adjust';
        $results = [];
        $failed = [];

        foreach ($data['items'] as $i => $item) {
            $productId = (int) $item['productId'];
            try {
                $delta = (int) $item['delta'];
                if ($delta === 0) {
                    throw new HttpException(422, 'Adjustment must not be zero');
                }
                $product = Product::where('company_id', $companyId)->find($productId);
                if (!$product) {
                    throw new HttpException(404, 'Product not found');
                }
                if ($product->is_bundle) {
                    throw new HttpException(422, 'Bundle stock is calculated from its components - adjust the components instead');
                }
                $variantId = !empty($item['variantId']) ? (int) $item['variantId'] : null;
                if (!$variantId && ProductVariant::where('product_id', $productId)->where('name', '<>', 'Default')->exists()) {
                    throw new HttpException(422, "Choose a variant — this product's stock is the sum of its variants");
                }
                if ($variantId && !ProductVariant::where('product_id', $productId)->where('id', $variantId)->exists()) {
                    throw new HttpException(422, 'Variant does not belong to this product');
                }
                $locationId = !empty($item['locationId']) ? (int) $item['locationId'] : $locations->defaultLocationId($companyId);

                $results[] = $adjuster->create($companyId, [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'location_id' => $locationId,
                    'type' => $delta > 0 ? 'increase' : 'decrease',
                    'quantity' => abs($delta),
                    'reason' => trim((string) ($item['reason'] ?? '')) ?: $defaultReason,
                ]);
            } catch (\Throwable $e) {
                $failed[] = ['productId' => $productId, 'index' => $i, 'reason' => $e->getMessage()];
            }
        }

        return ['adjusted' => count($results), 'failed' => $failed, 'results' => $results];
    }

    /**
     * Import products row by row. Each row is validated independently; invalid rows are skipped
     * with a reason. Category / brand / supplier / unit are matched by name; opening stock is
     * booked at the default warehouse through the inventory service.
     *
     * @return array{imported:int, skipped:array<int,array{row:int,reason:string}>, warnings:array}
     */
    public function import(int $companyId, array $rows): array
    {
        $pick = function (array $r, array $keys) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $r) && $r[$k] !== null && trim((string) $r[$k]) !== '') {
                    return is_string($r[$k]) ? trim($r[$k]) : $r[$k];
                }
            }
            return null;
        };
        $byName = fn (string $model, string $col) => $model::where('company_id', $companyId)->get([ 'id', $col])
            ->mapWithKeys(fn ($m) => [mb_strtolower(trim($m->{$col})) => $m->id])->all();
        $categories = $byName(Category::class, 'category_name');
        $brands = $byName(\App\Models\Brand::class, 'brand_name');
        $units = $byName(\App\Models\Unit::class, 'unit_name');
        $vendors = $byName(Vendor::class, 'name');

        $locationSvc = app(\App\Services\Inventory\LocationStockService::class);
        $locationId = null;

        $seenSku = [];
        $seenBarcode = [];
        $imported = 0;
        $skipped = [];
        $warnings = [];

        foreach ($rows as $idx => $r) {
            $n = $idx + 1; // 1-based data row number
            $fail = function (string $why) use (&$skipped, $n) {
                $skipped[] = ['row' => $n, 'reason' => $why];
            };
            $r = (array) $r;

            $name = $pick($r, ['name', 'Name']);
            $sku = $pick($r, ['sku', 'SKU']);
            $barcode = $pick($r, ['barcode', 'Barcode']);
            $priceRaw = $pick($r, ['price', 'Price', 'salePrice', 'sale_price', 'sellingPrice']);
            $costRaw = $pick($r, ['costPrice', 'cost_price', 'cost', 'Cost']);
            $stockRaw = $pick($r, ['stock', 'Stock']);
            $reorderRaw = $pick($r, ['reorderPoint', 'reorder_point', 'reorder', 'Reorder']);
            $offerRaw = $pick($r, ['offerPrice', 'offer_price', 'offer', 'Offer']);

            if ($name === null) { $fail('Missing name'); continue; }
            if ($sku === null) { $fail('Missing SKU'); continue; }
            if ($priceRaw === null) { $fail('Missing price'); continue; }
            if (!is_numeric($priceRaw) || (float) $priceRaw <= 0) { $fail('Price must be a number greater than 0'); continue; }
            if ($costRaw !== null && (!is_numeric($costRaw) || (float) $costRaw < 0)) { $fail('Cost must be a number of 0 or more'); continue; }
            if ($stockRaw !== null && (!is_numeric($stockRaw) || (float) $stockRaw < 0 || floor((float) $stockRaw) != (float) $stockRaw)) { $fail('Stock must be a whole number of 0 or more'); continue; }
            if ($reorderRaw !== null && (!is_numeric($reorderRaw) || (float) $reorderRaw < 0)) { $fail('Reorder point must be a number of 0 or more'); continue; }
            if ($offerRaw !== null && (!is_numeric($offerRaw) || (float) $offerRaw < 0 || (float) $offerRaw >= (float) $priceRaw)) { $fail('Offer must be a number below the price'); continue; }

            $skuKey = mb_strtolower((string) $sku);
            if (isset($seenSku[$skuKey])) { $fail("Duplicate SKU '{$sku}' in file (same as row {$seenSku[$skuKey]})"); continue; }
            if ($barcode !== null) {
                $bKey = mb_strtolower((string) $barcode);
                if (isset($seenBarcode[$bKey])) { $fail("Duplicate barcode '{$barcode}' in file (same as row {$seenBarcode[$bKey]})"); continue; }
            }
            if ($owner = $this->findCodeOwner($companyId, 'sku', (string) $sku)) { $fail("SKU '{$sku}' already exists ({$owner})"); continue; }
            if ($barcode !== null && ($owner = $this->findCodeOwner($companyId, 'barcode', (string) $barcode))) { $fail("Barcode '{$barcode}' already exists ({$owner})"); continue; }

            $match = function (string $label, ?string $value, array $map) use (&$warnings, $n) {
                if ($value === null) {
                    return null;
                }
                $id = $map[mb_strtolower($value)] ?? null;
                if ($id === null) {
                    $warnings[] = ['row' => $n, 'message' => "{$label} '{$value}' not found - left blank"];
                }
                return $id;
            };
            $categoryId = $match('Category', $pick($r, ['category', 'Category']), $categories);
            $brandId = $match('Brand', $pick($r, ['brand', 'Brand']), $brands);
            $unitId = $match('Unit', $pick($r, ['unit', 'Unit']), $units);
            $vendorId = $match('Supplier', $pick($r, ['supplier', 'Supplier', 'vendor']), $vendors);

            $published = $pick($r, ['published', 'Published', 'status', 'Status']);
            $isPublished = $published === null ? true : !in_array(mb_strtolower((string) $published), ['0', 'false', 'no', 'draft', 'hidden', 'unpublished', 'inactive'], true);

            $stockQty = (int) ($stockRaw ?? 0);
            try {
                DB::transaction(function () use ($companyId, $r, $pick, $name, $sku, $barcode, $priceRaw, $costRaw, $offerRaw, $reorderRaw, $stockQty, $categoryId, $brandId, $unitId, $vendorId, $isPublished, $locationSvc, &$locationId) {
                    $product = Product::create([
                        'company_id' => $companyId,
                        'name' => (string) $name,
                        'description' => $pick($r, ['description', 'Description']),
                        'sku' => (string) $sku,
                        'barcode' => $barcode !== null ? (string) $barcode : null,
                        'price' => (float) $priceRaw,
                        'sale_price' => (float) $priceRaw,
                        'offer_price' => $offerRaw !== null ? (float) $offerRaw : null,
                        'offer_type' => $offerRaw !== null ? 'flat' : null,
                        'cost_price' => $costRaw !== null ? (float) $costRaw : 0,
                        'stock' => 0,
                        'reorder_point' => (int) ($reorderRaw ?? 0),
                        'category_id' => $categoryId,
                        'brand_id' => $brandId,
                        'unit_id' => $unitId,
                        'vendor_id' => $vendorId,
                        'published' => $isPublished,
                    ]);

                    if (!$barcode && $this->barcodeService) {
                        try {
                            $this->barcodeService->createProductBarcode($product);
                        } catch (\Throwable $e) {
                            Log::warning('Import barcode generation failed', ['error' => $e->getMessage()]);
                        }
                    }

                    if ($stockQty > 0) {
                        $locationId ??= $locationSvc->defaultLocationId($companyId);
                        $locationSvc->change($companyId, $product->id, null, $locationId, $stockQty, [
                            'type' => 'receive',
                            'reference_type' => 'opening',
                            'reason' => 'Opening stock (import)',
                            'unit_cost' => $costRaw !== null ? (float) $costRaw : null,
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                $fail('Could not import: ' . $e->getMessage());
                continue;
            }

            $seenSku[$skuKey] = $n;
            if ($barcode !== null) {
                $seenBarcode[mb_strtolower((string) $barcode)] = $n;
            }
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'warnings' => $warnings];
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
            $vendor = Vendor::where('id', $vendorId)->exists();

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

        if (isset($data['unitId'])) {
            $dbData['unit_id'] = $data['unitId'];
        } elseif (isset($data['unit_id'])) {
            $dbData['unit_id'] = $data['unit_id'];
        }

        if (isset($data['brandId'])) {
            $dbData['brand_id'] = $data['brandId'];
        } elseif (isset($data['brand_id'])) {
            $dbData['brand_id'] = $data['brand_id'];
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

        if (array_key_exists('offerPrice', $data)) {
            $dbData['offer_price'] = $data['offerPrice'] !== '' && $data['offerPrice'] !== null ? (float) $data['offerPrice'] : null;
        } elseif (array_key_exists('offer_price', $data)) {
            $dbData['offer_price'] = $data['offer_price'] !== '' && $data['offer_price'] !== null ? (float) $data['offer_price'] : null;
        }

        if (array_key_exists('offerType', $data)) {
            $dbData['offer_type'] = $data['offerType'] ?: null;
        } elseif (array_key_exists('offer_type', $data)) {
            $dbData['offer_type'] = $data['offer_type'] ?: null;
        }

        foreach (['offer_ends_at', 'wholesale_price', 'min_wholesale_qty'] as $col) {
            if (array_key_exists($col, $data)) {
                $v = $data[$col];
                $dbData[$col] = ($v === '' || $v === null) ? null : ($col === 'wholesale_price' ? (float) $v : ($col === 'min_wholesale_qty' ? (int) $v : $v));
            }
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
        if (isset($data['reorderPoint'])) {
            $dbData['reorder_point'] = (int) $data['reorderPoint'];
        } elseif (isset($data['reorder_point'])) {
            $dbData['reorder_point'] = (int) $data['reorder_point'];
        }
        if (isset($data['trackingType'])) {
            $dbData['tracking_type'] = $data['trackingType'];
        } elseif (isset($data['tracking_type'])) {
            $dbData['tracking_type'] = $data['tracking_type'];
        }
        if (isset($data['isBundle'])) {
            $dbData['is_bundle'] = (bool) $data['isBundle'];
        } elseif (isset($data['is_bundle'])) {
            $dbData['is_bundle'] = (bool) $data['is_bundle'];
        }
        if (array_key_exists('bundlePriceOverride', $data)) {
            $dbData['bundle_price_override'] = $data['bundlePriceOverride'] !== null && $data['bundlePriceOverride'] !== '' ? (float) $data['bundlePriceOverride'] : null;
        }
        if (isset($data['sku'])) {
            $dbData['sku'] = $data['sku'];
        }
        if (isset($data['barcode'])) {
            $dbData['barcode'] = $data['barcode'];
        }
        if (isset($data['barcode_type'])) {
            $dbData['barcode_type'] = $data['barcode_type'];
        }
        if (isset($data['published'])) {
            $dbData['published'] = $data['published'];
        }
        foreach (['available_in_pos', 'visible_online'] as $flag) {
            if (isset($data[$flag])) {
                $dbData[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('tag', $data)) {
            $t = trim((string) $data['tag']);
            $dbData['tag'] = $t === '' ? null : mb_substr($t, 0, 50);
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

        if (array_key_exists('offerPrice', $data)) {
            $dbData['offer_price'] = $data['offerPrice'] !== '' && $data['offerPrice'] !== null ? (float) $data['offerPrice'] : null;
        } elseif (array_key_exists('offer_price', $data)) {
            $dbData['offer_price'] = $data['offer_price'] !== '' && $data['offer_price'] !== null ? (float) $data['offer_price'] : null;
        }

        if (array_key_exists('offerType', $data)) {
            $dbData['offer_type'] = $data['offerType'] ?: null;
        } elseif (array_key_exists('offer_type', $data)) {
            $dbData['offer_type'] = $data['offer_type'] ?: null;
        }

        foreach (['wholesalePrice' => 'wholesale_price', 'minWholesaleQty' => 'min_wholesale_qty'] as $camel => $snake) {
            $key = array_key_exists($camel, $data) ? $camel : (array_key_exists($snake, $data) ? $snake : null);
            if ($key !== null) {
                $val = $data[$key];
                $dbData[$snake] = $val !== '' && $val !== null ? ($snake === 'wholesale_price' ? (float) $val : (int) $val) : null;
            }
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
        if (isset($data['reorderPoint'])) {
            $dbData['reorder_point'] = (int) $data['reorderPoint'];
        } elseif (isset($data['reorder_point'])) {
            $dbData['reorder_point'] = (int) $data['reorder_point'];
        }
        if (isset($data['sku'])) {
            $dbData['sku'] = $data['sku'];
        }
        if (isset($data['barcode'])) {
            $dbData['barcode'] = $data['barcode'];
        }
        if (isset($data['barcode_type'])) {
            $dbData['barcode_type'] = $data['barcode_type'];
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

    private function syncBundleStock(Product $bundle): void
    {
        $items = $bundle->bundleItems()->with('product', 'variant')->get();

        if ($items->isEmpty()) {
            return;
        }

        $available = null;
        $totalCost  = 0;

        foreach ($items as $item) {
            $qty = max(1, (int) $item->quantity);

            if ($item->variant_id && $item->variant) {
                $childStock = (int) $item->variant->stock;
                $childCost  = (float) ($item->variant->cost_price ?? $item->product->cost_price ?? 0);
            } elseif ($item->product) {
                $childStock = (int) $item->product->stock;
                $childCost  = (float) ($item->product->cost_price ?? 0);
            } else {
                $childStock = 0;
                $childCost  = 0;
            }

            $slots      = (int) floor($childStock / $qty);
            $available  = $available === null ? $slots : min($available, $slots);
            $totalCost += $childCost * $qty;
        }

        $bundle->update([
            'stock'      => max(0, $available ?? 0),
            'cost_price' => $totalCost > 0 ? $totalCost : $bundle->cost_price,
        ]);
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
