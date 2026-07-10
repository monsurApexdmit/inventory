<?php

namespace App\Services\Barcode;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;
use Exception;

/**
 * BarcodeService - POS Barcode Scanning System
 *
 * Handles barcode generation and lookup for POS scanning
 * Supports both product and variant barcodes
 */
class BarcodeServicePOS
{
    /**
     * Find product or variant by barcode code
     *
     * @param string $code The barcode code to search for
     * @param int $companyId Company ID for multi-tenancy
     * @return array|null Result array with product/variant details or null if not found
     */
    public function findByBarcode(string $code, int $companyId): ?array
    {
        // Search product barcode first
        $product = Product::where('company_id', $companyId)
            ->where('barcode_code', $code)
            ->first();

        if ($product) {
            return [
                'type' => 'product',
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'barcode_code' => $product->barcode_code,
                'barcode_format' => $product->barcode_format ?? 'CODE128',
                'variant_id' => null,
                'variant_name' => null,
                'attributes' => null,
                'price' => (float) ($product->sale_price ?? $product->price),
                'stock' => $product->stock,
            ];
        }

        // Search variant barcode
        $variant = ProductVariant::where('barcode_code', $code)
            ->with('product')
            ->first();

        if ($variant && $variant->product->company_id == $companyId) {
            return [
                'type' => 'variant',
                'product_id' => $variant->product_id,
                'product_name' => $variant->product->name,
                'product_sku' => $variant->product->sku,
                'barcode_code' => $variant->barcode_code,
                'barcode_format' => $variant->barcode_format ?? 'CODE128',
                'variant_id' => $variant->id,
                'variant_name' => $variant->name,
                'attributes' => $variant->attributes ?? [],
                'price' => (float) ($variant->sale_price ?? $variant->price),
                'stock' => $variant->stock,
            ];
        }

        return null;
    }

    /**
     * Generate unique barcode code
     *
     * Format: PROD-XXXXXXXX-XXXX or VAR-XXXXXXXX-XXXX
     *
     * @param string $type Type of barcode (PROD or VAR)
     * @return string Generated barcode code
     * @throws Exception If unable to generate unique code after 10 attempts
     */
    public function generateBarcodeCode(string $type = 'PROD'): string
    {
        $prefix = $type === 'VAR' ? 'V' : 'P';
        $maxAttempts = 10;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $code = $prefix . strtoupper(Str::random(8));

            if (!$this->barcodeExists($code)) {
                return $code;
            }

            $attempt++;
        }

        throw new Exception("Unable to generate unique barcode code after {$maxAttempts} attempts");
    }

    /**
     * Check if barcode code already exists
     *
     * @param string $code Barcode code to check
     * @return bool True if barcode exists, false otherwise
     */
    private function barcodeExists(string $code): bool
    {
        $inProducts = Product::where('barcode_code', $code)->exists();
        $inVariants = ProductVariant::where('barcode_code', $code)->exists();

        return $inProducts || $inVariants;
    }

    /**
     * Auto-generate barcodes for products/variants without barcodes
     *
     * @param int|null $limit Maximum number of records to process
     * @return array Statistics of generation
     */
    public function generateMissingBarcodes(int $limit = null): array
    {
        $productsGenerated = 0;
        $variantsGenerated = 0;
        $errors = [];

        try {
            // Generate for products without barcodes
            $products = Product::whereNull('barcode_code')
                ->limit($limit ?? 1000)
                ->get();

            foreach ($products as $product) {
                try {
                    $barcodeCode = $this->generateBarcodeCode('PROD');
                    $product->update([
                        'barcode_code' => $barcodeCode,
                        'barcode_format' => 'CODE128',
                    ]);
                    $productsGenerated++;
                } catch (Exception $e) {
                    $errors[] = "Product {$product->id}: {$e->getMessage()}";
                }
            }

            // Generate for variants without barcodes
            $variants = ProductVariant::whereNull('barcode_code')
                ->limit($limit ?? 5000)
                ->get();

            foreach ($variants as $variant) {
                try {
                    $barcodeCode = $this->generateBarcodeCode('VAR');
                    $variant->update([
                        'barcode_code' => $barcodeCode,
                        'barcode_format' => 'CODE128',
                    ]);
                    $variantsGenerated++;
                } catch (Exception $e) {
                    $errors[] = "Variant {$variant->id}: {$e->getMessage()}";
                }
            }
        } catch (Exception $e) {
            \Log::error('Barcode generation failed', ['error' => $e->getMessage()]);
        }

        return [
            'products_generated' => $productsGenerated,
            'variants_generated' => $variantsGenerated,
            'total' => $productsGenerated + $variantsGenerated,
            'errors' => $errors,
        ];
    }

    /**
     * Get barcode statistics
     *
     * @return array Statistics about barcode coverage
     */
    public function getStatistics(): array
    {
        $productStats = Product::select(
            \DB::raw('COUNT(*) as total'),
            \DB::raw('SUM(CASE WHEN barcode_code IS NOT NULL THEN 1 ELSE 0 END) as with_barcode')
        )->first();

        $variantStats = ProductVariant::select(
            \DB::raw('COUNT(*) as total'),
            \DB::raw('SUM(CASE WHEN barcode_code IS NOT NULL THEN 1 ELSE 0 END) as with_barcode')
        )->first();

        $productPercentage = $productStats->total > 0
            ? round(100 * $productStats->with_barcode / $productStats->total, 2)
            : 0;

        $variantPercentage = $variantStats->total > 0
            ? round(100 * $variantStats->with_barcode / $variantStats->total, 2)
            : 0;

        return [
            'products' => [
                'total' => $productStats->total,
                'with_barcode' => $productStats->with_barcode,
                'without_barcode' => $productStats->total - $productStats->with_barcode,
                'coverage_percentage' => $productPercentage,
            ],
            'variants' => [
                'total' => $variantStats->total,
                'with_barcode' => $variantStats->with_barcode,
                'without_barcode' => $variantStats->total - $variantStats->with_barcode,
                'coverage_percentage' => $variantPercentage,
            ],
        ];
    }
}
