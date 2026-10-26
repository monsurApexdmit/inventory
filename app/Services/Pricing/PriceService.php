<?php

namespace App\Services\Pricing;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Single source of truth for what a product (or variant) costs a buyer right now:
 * normal price, a live offer (expired offers are ignored, end date inclusive) and the
 * wholesale price that applies from a minimum quantity.
 */
class PriceService
{
    /** Normal selling price: the sale price when set, else the list price. */
    public static function basePrice(Product $product, ?ProductVariant $variant = null): float
    {
        if ($variant) {
            $sale = (float) $variant->sale_price;

            return $sale > 0 ? $sale : (float) $variant->price;
        }
        if ($product->is_bundle && (float) $product->bundle_price_override > 0) {
            return (float) $product->bundle_price_override;
        }
        $sale = (float) $product->sale_price;

        return $sale > 0 ? $sale : (float) $product->price;
    }

    /** The offer in force (variant's own, else the product's) or null when none / expired. */
    public static function activeOffer(Product $product, ?ProductVariant $variant = null): ?array
    {
        if (!$product->isOfferWindowOpen()) {
            return null;
        }
        $value = $variant && (float) $variant->offer_price > 0 ? (float) $variant->offer_price : (float) $product->offer_price;
        if ($value <= 0) {
            return null;
        }
        $type = ($variant && (float) $variant->offer_price > 0 ? $variant->offer_type : null) ?: ($product->offer_type ?: 'percentage');

        return ['value' => $value, 'type' => $type];
    }

    /** Price after a live offer, or null when no offer applies. */
    public static function offerPrice(Product $product, ?ProductVariant $variant = null): ?float
    {
        $offer = self::activeOffer($product, $variant);
        if (!$offer) {
            return null;
        }
        $base = self::basePrice($product, $variant);
        $price = $offer['type'] === 'percentage' ? $base * (1 - $offer['value'] / 100) : $base - $offer['value'];

        return round(max(0, $price), 2);
    }

    /**
     * Wholesale price when the quantity reaches the minimum, else null.
     * A variant uses its own wholesale price / min qty; whatever it leaves empty falls back to the
     * parent product's values (so a product-level tier covers all its variants).
     */
    public static function wholesalePrice(Product $product, ?ProductVariant $variant, float $quantity): ?float
    {
        $price = (float) ($variant?->wholesale_price ?? 0);
        $minRaw = $variant?->min_wholesale_qty;
        if ($price <= 0) {
            $price = (float) $product->wholesale_price;
            $minRaw = $product->min_wholesale_qty;
        } elseif ($minRaw === null || (int) $minRaw <= 0) {
            $minRaw = $product->min_wholesale_qty;
        }
        if ($price <= 0 || (int) $minRaw <= 0 || $quantity < (int) $minRaw) {
            return null;
        }

        return round($price, 2);
    }

    /**
     * Resolve the price for a line.
     *
     * @return array{unit: float, base: float, offer: ?float, wholesale: ?float, source: string, offerActive: bool, valid: float[]}
     *   `unit` is the price to charge; `valid` lists every price the line may legitimately carry.
     */
    public static function resolve(Product $product, ?ProductVariant $variant = null, float $quantity = 1): array
    {
        $base = round(self::basePrice($product, $variant), 2);
        $offer = self::offerPrice($product, $variant);
        $wholesale = self::wholesalePrice($product, $variant, $quantity);

        $unit = $base;
        $source = 'normal';
        if ($offer !== null && $offer < $unit) {
            $unit = $offer;
            $source = 'offer';
        }
        if ($wholesale !== null && $wholesale < $unit) {
            $unit = $wholesale;
            $source = 'wholesale';
        }

        return [
            'unit' => $unit,
            'base' => $base,
            'offer' => $offer,
            'wholesale' => $wholesale,
            'source' => $source,
            'offerActive' => $offer !== null,
            'valid' => array_values(array_filter([$base, $offer, $wholesale], fn ($v) => $v !== null)),
        ];
    }
}
