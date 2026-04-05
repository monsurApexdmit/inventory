<?php

namespace App\Services\StockTransfer;

use App\DTOs\StockTransfer\StockTransferDTO;
use App\DTOs\StockTransfer\StockTransferMapper;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\VariantInventory;
use App\Repositories\Contracts\IStockTransferRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockTransferService
{
    private readonly StockTransferMapper $mapper;

    public function __construct(private readonly IStockTransferRepository $repository)
    {
        $this->mapper = new StockTransferMapper();
    }

    /**
     * List all transfers for a company with filters
     */
    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($transfer) => $this->mapper->toDTO($transfer), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    /**
     * Get products available by location
     */
    public function getProductsByLocation(int $companyId, int $locationId, array $filters): array
    {
        $paginated = $this->repository->getProductsByLocation($companyId, $locationId, $filters);
        return [
            'data' => $paginated->items(),
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    /**
     * Create a stock transfer
     *
     * Handles two paths:
     * 1. Variant path: when variantId is provided
     * 2. Simple product path: when variantId is omitted
     */
    public function createTransfer(int $companyId, array $data): StockTransferDTO
    {
        $productId = $data['product_id'];
        $variantId = $data['variant_id'] ?? null;
        $fromLocationId = $data['from_location_id'];
        $toLocationId = $data['to_location_id'];
        $quantity = $data['quantity'];

        // Validate locations are different
        if ($fromLocationId === $toLocationId) {
            throw new HttpException(400, 'From location and to location must be different');
        }

        // Verify locations exist and belong to company
        $fromLocation = Location::where('company_id', $companyId)->find($fromLocationId);
        $toLocation = Location::where('company_id', $companyId)->find($toLocationId);

        if (!$fromLocation || !$toLocation) {
            throw new HttpException(404, 'One or both locations not found');
        }

        // Verify product exists and belongs to company
        $product = Product::where('company_id', $companyId)->find($productId);
        if (!$product) {
            throw new HttpException(404, 'Product not found');
        }

        if ($variantId) {
            return $this->transferVariantStock($companyId, $product, $variantId, $fromLocationId, $toLocationId, $quantity, $data['notes'] ?? null);
        } else {
            return $this->transferSimpleProductStock($companyId, $product, $fromLocationId, $toLocationId, $quantity, $data['notes'] ?? null);
        }
    }

    /**
     * Transfer variant stock
     *
     * 1. Look up variant_inventory row for (variant_id, from_location_id)
     * 2. If no row, fallback to product_variants.stock if product.location_id matches
     * 3. Check sufficient stock → 400 if not
     * 4. Deduct from source variant_inventory
     * 5. Upsert destination variant_inventory
     * 6. Sync product_variants.stock = SUM all variant_inventory
     * 7. Create stock_transfers record
     */
    private function transferVariantStock(
        int $companyId,
        Product $product,
        int $variantId,
        int $fromLocationId,
        int $toLocationId,
        int $quantity,
        ?string $notes
    ): StockTransferDTO {
        $variant = ProductVariant::find($variantId);
        if (!$variant || $variant->product_id !== $product->id) {
            throw new HttpException(404, 'Variant not found or does not belong to this product');
        }

        return DB::transaction(function () use ($product, $variant, $fromLocationId, $toLocationId, $quantity, $notes, $companyId) {
            // Look up variant inventory for source location
            $sourceInventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $fromLocationId)
                ->first();

            // Check if variant exists and has stock, or fallback to product stock
            if (!$sourceInventory) {
                // Fallback: check if product.location_id matches and has stock
                if ($product->location_id === $fromLocationId && $product->stock >= $quantity) {
                    // Create the variant inventory record if it doesn't exist
                    $sourceInventory = VariantInventory::create([
                        'variant_id' => $variant->id,
                        'location_id' => $fromLocationId,
                        'quantity' => $product->stock,
                    ]);
                } else {
                    throw new HttpException(400, 'Insufficient stock in source location');
                }
            }

            // Check sufficient stock
            if ($sourceInventory->quantity < $quantity) {
                throw new HttpException(400, 'Insufficient stock in source location');
            }

            // Deduct from source
            $sourceInventory->quantity -= $quantity;
            $sourceInventory->save();

            // Upsert destination
            $destInventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $toLocationId)
                ->first();

            if ($destInventory) {
                $destInventory->quantity += $quantity;
                $destInventory->save();
            } else {
                VariantInventory::create([
                    'variant_id' => $variant->id,
                    'location_id' => $toLocationId,
                    'quantity' => $quantity,
                ]);
            }

            // Sync product_variants.stock
            $totalStock = VariantInventory::where('variant_id', $variant->id)->sum('quantity');
            $variant->stock = $totalStock;
            $variant->save();

            // Create transfer record
            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
                'status' => 'Completed',
                'notes' => $notes,
            ]);

            return $this->mapper->toDTO($transfer->load(['product', 'variant', 'fromLocation', 'toLocation', 'company']));
        });
    }

    /**
     * Transfer simple product stock
     *
     * Simple products use a virtual variant (with variant_id = product_id) in variant_inventory.
     * This enables true multi-location tracking for simple products.
     *
     * 1. Get or create virtual variant for simple product
     * 2. Look up variant_inventory at source location
     * 3. Check sufficient stock → 400 if not
     * 4. Deduct from source variant_inventory
     * 5. Upsert destination variant_inventory (CREATE or ADD to existing)
     * 6. Sync product.stock = SUM of all variant_inventory
     * 7. Create stock_transfers record
     */
    private function transferSimpleProductStock(
        int $companyId,
        Product $product,
        int $fromLocationId,
        int $toLocationId,
        int $quantity,
        ?string $notes
    ): StockTransferDTO {
        return DB::transaction(function () use ($product, $fromLocationId, $toLocationId, $quantity, $notes, $companyId) {
            // Get or create a virtual variant for this simple product
            $variant = ProductVariant::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'name' => 'Default',  // Simple products have a single "Default" variant
                ],
                [
                    'sku' => $product->sku,
                    'price' => $product->price,
                    'stock' => 0,
                ]
            );

            // Look up or create variant_inventory at source location
            $sourceInventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $fromLocationId)
                ->first();

            // If no inventory exists, create it from product.stock (migration scenario)
            if (!$sourceInventory) {
                if ($product->location_id === $fromLocationId && $product->stock > 0) {
                    // Migrate existing product stock to variant_inventory
                    $sourceInventory = VariantInventory::create([
                        'variant_id' => $variant->id,
                        'location_id' => $fromLocationId,
                        'quantity' => $product->stock,
                    ]);
                } else {
                    throw new HttpException(400, 'Product is not stored in the source location');
                }
            }

            // Check sufficient stock
            if ($sourceInventory->quantity < $quantity) {
                throw new HttpException(400, 'Insufficient stock for transfer');
            }

            // Deduct from source
            $sourceInventory->quantity -= $quantity;
            $sourceInventory->save();

            // Upsert destination - create or add to existing
            $destInventory = VariantInventory::where('variant_id', $variant->id)
                ->where('location_id', $toLocationId)
                ->first();

            if ($destInventory) {
                $destInventory->quantity += $quantity;
                $destInventory->save();
            } else {
                VariantInventory::create([
                    'variant_id' => $variant->id,
                    'location_id' => $toLocationId,
                    'quantity' => $quantity,
                ]);
            }

            // Sync product.stock = SUM of all variant_inventory for this variant
            $totalStock = VariantInventory::where('variant_id', $variant->id)->sum('quantity');
            $product->stock = $totalStock;

            // Update location to a location with stock (prefer destination if stock is there)
            $locationsWithStock = VariantInventory::where('variant_id', $variant->id)
                ->where('quantity', '>', 0)
                ->pluck('location_id')
                ->toArray();

            if (count($locationsWithStock) === 1) {
                $product->location_id = $locationsWithStock[0];
            } elseif (in_array($toLocationId, $locationsWithStock)) {
                $product->location_id = $toLocationId;
            }

            $product->save();

            // Sync variant stock
            $variant->stock = $totalStock;
            $variant->save();

            // Create transfer record (variant_id stays null for simple products in stock_transfers)
            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'variant_id' => null,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity,
                'status' => 'Completed',
                'notes' => $notes,
            ]);

            return $this->mapper->toDTO($transfer->load(['product', 'variant', 'fromLocation', 'toLocation', 'company']));
        });
    }

    /**
     * Cancel a transfer
     *
     * 1. Find transfer by ID within company scope → 404
     * 2. Check status == 'Completed' → 400 if not
     * 3. Reverse stock movement
     * 4. Update transfer status to 'Cancelled'
     */
    public function cancelTransfer(int $companyId, int $transferId): StockTransferDTO
    {
        $transfer = $this->repository->findByIdAndCompany($transferId, $companyId);

        if (!$transfer) {
            throw new HttpException(404, 'Transfer not found');
        }

        if ($transfer->status !== 'Completed') {
            throw new HttpException(400, 'Only completed transfers can be cancelled');
        }

        return DB::transaction(function () use ($transfer, $companyId) {
            if ($transfer->variant_id) {
                // Reverse variant stock movement
                $this->reverseVariantTransfer($transfer);
            } else {
                // Reverse simple product stock movement
                $this->reverseSimpleProductTransfer($transfer);
            }

            // Update transfer status
            $transfer->status = 'Cancelled';
            $transfer->save();

            return $this->mapper->toDTO($transfer->load(['product', 'variant', 'fromLocation', 'toLocation', 'company']));
        });
    }

    /**
     * Reverse a variant transfer
     */
    private function reverseVariantTransfer(StockTransfer $transfer): void
    {
        // Add back to source location
        $sourceInventory = VariantInventory::where('variant_id', $transfer->variant_id)
            ->where('location_id', $transfer->from_location_id)
            ->first();

        if ($sourceInventory) {
            $sourceInventory->quantity += $transfer->quantity;
            $sourceInventory->save();
        } else {
            VariantInventory::create([
                'variant_id' => $transfer->variant_id,
                'location_id' => $transfer->from_location_id,
                'quantity' => $transfer->quantity,
            ]);
        }

        // Deduct from destination location
        $destInventory = VariantInventory::where('variant_id', $transfer->variant_id)
            ->where('location_id', $transfer->to_location_id)
            ->first();

        if ($destInventory) {
            $destInventory->quantity -= $transfer->quantity;
            if ($destInventory->quantity <= 0) {
                $destInventory->delete();
            } else {
                $destInventory->save();
            }
        }

        // Sync variant stock
        $totalStock = VariantInventory::where('variant_id', $transfer->variant_id)->sum('quantity');
        $variant = ProductVariant::find($transfer->variant_id);
        if ($variant) {
            $variant->stock = $totalStock;
            $variant->save();
        }
    }

    /**
     * Reverse a simple product transfer
     */
    private function reverseSimpleProductTransfer(StockTransfer $transfer): void
    {
        $product = Product::find($transfer->product_id);
        if (!$product) {
            return;
        }

        // Get or create the virtual variant for this simple product
        $variant = ProductVariant::firstOrCreate(
            [
                'product_id' => $product->id,
                'name' => 'Default',
            ],
            [
                'sku' => $product->sku,
                'price' => $product->price,
                'stock' => 0,
            ]
        );

        // Add back to source location
        $sourceInventory = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $transfer->from_location_id)
            ->first();

        if ($sourceInventory) {
            $sourceInventory->quantity += $transfer->quantity;
            $sourceInventory->save();
        } else {
            VariantInventory::create([
                'variant_id' => $variant->id,
                'location_id' => $transfer->from_location_id,
                'quantity' => $transfer->quantity,
            ]);
        }

        // Deduct from destination location
        $destInventory = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $transfer->to_location_id)
            ->first();

        if ($destInventory) {
            $destInventory->quantity -= $transfer->quantity;
            if ($destInventory->quantity <= 0) {
                $destInventory->delete();
            } else {
                $destInventory->save();
            }
        }

        // Sync product and variant stock
        $totalStock = VariantInventory::where('variant_id', $variant->id)->sum('quantity');
        $product->stock = $totalStock;

        // Update location based on where stock is
        $locationsWithStock = VariantInventory::where('variant_id', $variant->id)
            ->where('quantity', '>', 0)
            ->pluck('location_id')
            ->toArray();

        if (count($locationsWithStock) === 1) {
            $product->location_id = $locationsWithStock[0];
        }

        $product->save();

        // Sync variant stock
        $variant->stock = $totalStock;
        $variant->save();
    }
}
