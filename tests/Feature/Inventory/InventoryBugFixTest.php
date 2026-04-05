<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaasUser;
use App\Models\StockTransfer;
use App\Models\VariantInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryBugFixTest extends TestCase
{
    use RefreshDatabase;

    private SaasUser $owner;
    private Company $company;
    private string $token;
    private Location $location1;
    private Location $location2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        $this->owner = SaasUser::create([
            'email' => 'owner@test.com',
            'full_name' => 'Test Owner',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
        ]);

        $this->token = JWTAuth::fromUser($this->owner);

        // Create locations
        $this->location1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $this->location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);
    }

    /**
     * Bug Fix 1: Inventory list should NOT show parent product if it has variants
     * Only show the variant products
     */
    public function test_inventory_excludes_parent_product_with_variants(): void
    {
        // Create a product with variants
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'T-Shirt',
            'sku' => 'TSHIRT',
            'price' => 29.99,
            'stock' => 0, // No stock in parent
            'published' => true,
        ]);

        // Create two variants
        $variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Red',
            'sku' => 'TSHIRT-RED',
            'price' => 29.99,
            'stock' => 0,
        ]);

        $variant2 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Blue',
            'sku' => 'TSHIRT-BLUE',
            'price' => 29.99,
            'stock' => 0,
        ]);

        // Add inventory to variants at location1
        VariantInventory::create([
            'variant_id' => $variant1->id,
            'location_id' => $this->location1->id,
            'quantity' => 10,
        ]);

        VariantInventory::create([
            'variant_id' => $variant2->id,
            'location_id' => $this->location1->id,
            'quantity' => 15,
        ]);

        // Get inventory
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have exactly 2 items (both variants, not the parent product)
        $this->assertCount(2, $data);

        // Both should be variants
        $this->assertEquals('variant', $data[0]['type']);
        $this->assertEquals('variant', $data[1]['type']);

        // Should not have the parent product
        $parentExists = array_filter($data, fn($item) => $item['type'] === 'product' && $item['productName'] === 'T-Shirt');
        $this->assertEmpty($parentExists, 'Parent product should not appear when it has variants');
    }

    /**
     * Bug Fix 2: Transfer page products should show variants, not parent product
     */
    public function test_transfer_page_excludes_parent_product_with_variants(): void
    {
        // Create a product with variants
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Jeans',
            'sku' => 'JEANS',
            'price' => 79.99,
            'stock' => 0,
            'published' => true,
        ]);

        $variant1 = ProductVariant::create([
            'product_id' => $product->id,
            'name' => '32',
            'sku' => 'JEANS-32',
            'price' => 79.99,
            'stock' => 0,
        ]);

        // Add inventory to variant at location1
        VariantInventory::create([
            'variant_id' => $variant1->id,
            'location_id' => $this->location1->id,
            'quantity' => 20,
        ]);

        // Get products by location (used on transfer page)
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/transfers/products-by-location/{$this->location1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have the product with variants array
        $this->assertCount(1, $data);
        $this->assertEquals('Jeans', $data[0]['name']);
        $this->assertArrayHasKey('variants', $data[0]);
        $this->assertCount(1, $data[0]['variants']);
        $this->assertEquals('32', $data[0]['variants'][0]['name']);
    }

    /**
     * Bug Fix 3: After transfer, both source and destination warehouses should show correct stock
     */
    public function test_inventory_updates_after_simple_product_transfer(): void
    {
        // Create a simple product at location1
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Shoes',
            'sku' => 'SHOES-001',
            'price' => 99.99,
            'stock' => 50,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Transfer 20 units from location1 to location2
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'product_id' => $product->id,
                'from_location_id' => $this->location1->id,
                'to_location_id' => $this->location2->id,
                'quantity' => 20,
            ]);

        $response->assertStatus(201);

        // Refresh product from DB
        $product->refresh();

        // Check inventory page - should show variant (Default) at both locations
        $inventoryResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $inventoryResponse->assertStatus(200);
        $data = $inventoryResponse->json('data');

        // Should have one item (the Default variant of simple product after transfer)
        $this->assertCount(1, $data);
        $this->assertEquals('variant', $data[0]['type']);
        $this->assertEquals('Default', $data[0]['variantName']);

        // Check that inventory array has both locations
        $inventory = $data[0]['inventory'];
        $this->assertCount(2, $inventory);

        // Find quantities at each location
        $location1Inventory = array_filter($inventory, fn($inv) => $inv['locationId'] === $this->location1->id);
        $location2Inventory = array_filter($inventory, fn($inv) => $inv['locationId'] === $this->location2->id);

        $this->assertNotEmpty($location1Inventory, 'Location1 should have inventory');
        $this->assertNotEmpty($location2Inventory, 'Location2 should have inventory');

        // Verify quantities
        $loc1Item = reset($location1Inventory);
        $loc2Item = reset($location2Inventory);

        $this->assertEquals(30, $loc1Item['quantity'], 'Location1 should have 30 (50 - 20)');
        $this->assertEquals(20, $loc2Item['quantity'], 'Location2 should have 20');
    }

    /**
     * Simple products that have never been transferred should still appear correctly
     */
    public function test_simple_product_without_transfer_appears_correctly(): void
    {
        // Create a simple product at location1
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Hat',
            'sku' => 'HAT-001',
            'price' => 19.99,
            'stock' => 30,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Get inventory
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have exactly 1 item
        $this->assertCount(1, $data);

        // Should be type 'product' (not variant)
        $this->assertEquals('product', $data[0]['type']);
        $this->assertEquals('Hat', $data[0]['productName']);
        $this->assertEquals(30, $data[0]['stock']);
    }

    /**
     * Test transfer page shows simple products correctly
     */
    public function test_transfer_page_shows_simple_products(): void
    {
        // Create a simple product
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Pen',
            'sku' => 'PEN-001',
            'price' => 2.99,
            'stock' => 100,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Get products by location (used on transfer page)
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/transfers/products-by-location/{$this->location1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have the product
        $this->assertCount(1, $data);
        $this->assertEquals('Pen', $data[0]['name']);
        $this->assertEquals(100, $data[0]['stock']);

        // Should NOT have a 'variants' key
        $this->assertArrayNotHasKey('variants', $data[0]);
    }

    /**
     * Test that after transfer, simple product appears correctly on transfer page
     * (as a flat product, not with variants, since it only has the virtual Default variant)
     */
    public function test_transfer_page_shows_transferred_simple_product_as_flat(): void
    {
        // Create a simple product
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Notebook',
            'sku' => 'NOTEBOOK-001',
            'price' => 5.99,
            'stock' => 50,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Transfer it
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'product_id' => $product->id,
                'from_location_id' => $this->location1->id,
                'to_location_id' => $this->location2->id,
                'quantity' => 30,
            ]);

        // Now check that on the transfer page at location2, it appears as a flat product
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/transfers/products-by-location/{$this->location2->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should have the product
        $this->assertCount(1, $data);
        $this->assertEquals('Notebook', $data[0]['name']);
        $this->assertEquals(30, $data[0]['stock']);

        // Should NOT have a 'variants' key (because the only variant is the virtual Default)
        $this->assertArrayNotHasKey('variants', $data[0]);
    }
}
