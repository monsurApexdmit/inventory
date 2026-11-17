<?php

namespace Tests\Feature\Storefront\Concerns;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sell;
use App\Models\ShippingMethod;
use App\Models\VariantInventory;
use App\Services\Storefront\StorefrontCheckoutService;

/** A store with two ships-online warehouses, a shop that does not ship online, a pickup point and a delivery option. */
trait CheckoutFixtures
{
    protected Company $company;
    protected Location $warehouseA;
    protected Location $warehouseB;
    protected Location $shop;
    protected Location $pickupPoint;
    protected ShippingMethod $delivery;

    protected function setUpStore(): void
    {
        $this->company = Company::factory()->create();
        $loc = fn (array $a) => Location::factory()->create(array_merge(['company_id' => $this->company->id, 'ships_online' => false, 'is_pickup_point' => false], $a));
        $this->warehouseA = $loc(['name' => 'Warehouse A', 'ships_online' => true, 'is_default' => true]);
        $this->warehouseB = $loc(['name' => 'Warehouse B', 'ships_online' => true]);
        $this->shop = $loc(['name' => 'Shop floor']);
        $this->pickupPoint = $loc(['name' => 'Pickup counter', 'is_pickup_point' => true]);
        $this->delivery = ShippingMethod::create([
            'company_id' => $this->company->id, 'name' => 'Standard', 'type' => 'delivery', 'price' => 0, 'is_active' => true,
        ]);
    }

    /** Simple product with per-location stock ([locationId => qty]) on its hidden Default variant. */
    protected function simpleProduct(array $stock, array $attrs = []): Product
    {
        $product = $this->baseProduct($attrs);
        $default = ProductVariant::create(['product_id' => $product->id, 'name' => 'Default', 'sku' => $product->sku, 'price' => 10, 'stock' => 0]);
        $this->putStock($default, $stock);
        $product->update(['stock' => array_sum($stock)]);

        return $product->fresh();
    }

    /**
     * Product with real variants. $variants: [name => [locationId => qty]].
     *
     * @return array{0: Product, 1: array<string, ProductVariant>}
     */
    protected function variantProduct(array $variants): array
    {
        $product = $this->baseProduct();
        $out = [];
        foreach ($variants as $name => $stock) {
            $variant = ProductVariant::create(['product_id' => $product->id, 'name' => $name, 'sku' => $product->sku . '-' . $name, 'price' => 10, 'stock' => 0]);
            $this->putStock($variant, $stock);
            $out[$name] = $variant->fresh();
        }
        $product->update(['stock' => (int) ProductVariant::where('product_id', $product->id)->sum('stock')]);

        return [$product->fresh(), $out];
    }

    /** Product on legacy flat stock (no variant / per-location rows). */
    protected function flatProduct(int $stock): Product
    {
        return $this->baseProduct(['stock' => $stock]);
    }

    protected function baseProduct(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'vendor_id' => null,
            'location_id' => $this->warehouseA->id,
            'published' => true,
            'is_bundle' => false,
            'price' => 10,
            'sale_price' => 10,
            'offer_price' => null,
            'wholesale_price' => null,
            'cost_price' => 4,
            'stock' => 0,
        ], $attrs));
    }

    protected function putStock(ProductVariant $variant, array $stock): void
    {
        foreach ($stock as $locationId => $qty) {
            VariantInventory::create(['variant_id' => $variant->id, 'location_id' => $locationId, 'quantity' => $qty]);
        }
        $variant->update(['stock' => array_sum($stock)]);
    }

    /** Units of a product (its Default variant) or a variant at one location, or at all locations. */
    protected function stockOf(Product|ProductVariant $item, ?int $locationId = null): int
    {
        $variantId = $item instanceof ProductVariant
            ? $item->id
            : ProductVariant::where('product_id', $item->id)->where('name', 'Default')->value('id');
        $q = VariantInventory::where('variant_id', $variantId);
        if ($locationId !== null) {
            $q->where('location_id', $locationId);
        }

        return (int) $q->sum('quantity');
    }

    /** Place a COD delivery order through the real checkout service. */
    protected function placeOrder(array $items, array $ctx = [], array $input = []): array
    {
        return app(StorefrontCheckoutService::class)->place((int) $this->company->id, array_merge([
            'items' => $items,
            'shipping_address' => ['name' => 'Buyer', 'phone' => '01700000000', 'address' => '1 Road'],
            'payment_method' => 'cod',
            'shipping_method_id' => $this->delivery->id,
        ], $input), array_merge(['customer_name' => 'Buyer'], $ctx));
    }

    /** Turn a placed order into an online-gateway order awaiting payment (as if a gateway had been chosen). */
    protected function awaitingPayment(Sell $sell, int $holdMinutes = 15): Sell
    {
        $sell->update(['payment_status' => 'pending_payment', 'stock_hold_expires_at' => now()->addMinutes($holdMinutes)]);

        return $sell->fresh();
    }

    protected function saleMovementsTotal(Sell $sell): int
    {
        return (int) InventoryMovement::where('reference_type', 'sell')->where('reference_id', $sell->id)->sum('quantity');
    }
}
