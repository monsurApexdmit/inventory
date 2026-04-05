<?php

namespace Tests\Feature\Inventory;

use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaasUser;
use App\Models\VariantInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InventoryTest extends TestCase
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
            'name' => 'Main Warehouse',
        ]);

        $this->location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Branch Store',
        ]);
    }

    public function test_list_inventory_simple_product(): void
    {
        // Create simple product with inventory
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'T-Shirt',
            'sku' => 'TSHIRT-001',
            'barcode' => '123456789',
            'price' => 29.99,
            'stock' => 100,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory?page=1&limit=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'type',
                    'id',
                    'productId',
                    'productName',
                    'variantName',
                    'sku',
                    'barcode',
                    'stock',
                    'inventory' => [
                        '*' => [
                            'locationId',
                            'locationName',
                            'quantity',
                        ]
                    ]
                ]
            ],
            'meta' => [
                'total',
                'page',
                'limit',
            ]
        ]);

        $data = $response->json('data.0');
        $this->assertEquals('product', $data['type']);
        $this->assertEquals('T-Shirt', $data['productName']);
        $this->assertEquals('', $data['variantName']); // Empty for simple products
        $this->assertEquals(100, $data['stock']); // Sum of all locations
    }

    public function test_list_inventory_variant_product(): void
    {
        // Create variant product
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Jeans',
            'sku' => 'JEANS',
            'price' => 79.99,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => '32 / Blue',
            'sku' => 'JEANS-32-BLU',
            'barcode' => 'VAR123',
            'price' => 79.99,
            'stock' => 0,
        ]);

        // Create variant inventory at two locations
        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location1->id,
            'quantity' => 30,
        ]);

        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location2->id,
            'quantity' => 20,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $response->assertStatus(200);
        $data = $response->json('data.0');

        $this->assertEquals('variant', $data['type']);
        $this->assertEquals('Jeans', $data['productName']);
        $this->assertEquals('32 / Blue', $data['variantName']);
        $this->assertEquals(50, $data['stock']); // 30 + 20
        $this->assertCount(2, $data['inventory']); // 2 locations
    }

    public function test_inventory_stock_equals_sum_of_locations(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Product',
            'sku' => 'SKU',
            'price' => 50,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant',
            'sku' => 'VAR-SKU',
            'price' => 50,
            'stock' => 0,
        ]);

        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location1->id,
            'quantity' => 25,
        ]);

        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location2->id,
            'quantity' => 35,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $data = $response->json('data.0');
        $locationSum = array_sum(array_column($data['inventory'], 'quantity'));

        $this->assertEquals($data['stock'], $locationSum);
    }

    public function test_search_by_product_name(): void
    {
        // Create multiple products with location and stock (simple products)
        Product::create([
            'company_id' => $this->company->id,
            'name' => 'Blue T-Shirt',
            'sku' => 'BLUE-001',
            'price' => 29.99,
            'stock' => 50,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        Product::create([
            'company_id' => $this->company->id,
            'name' => 'Red Jeans',
            'sku' => 'RED-001',
            'price' => 79.99,
            'stock' => 30,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Search for "T-Shirt"
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory?search=T-Shirt');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('Blue T-Shirt', $response->json('data.0.productName'));
    }

    public function test_search_by_sku(): void
    {
        Product::create([
            'company_id' => $this->company->id,
            'name' => 'Shirt',
            'sku' => 'SHIRT-BLUE-001',
            'price' => 29.99,
            'stock' => 50,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory?search=BLUE');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_filter_by_location(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Product',
            'sku' => 'SKU',
            'price' => 50,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Variant',
            'sku' => 'VAR-SKU',
            'price' => 50,
            'stock' => 0,
        ]);

        // Add inventory only at location 1
        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location1->id,
            'quantity' => 100,
        ]);

        // Filter by location 1 - should return the item
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/inventory?location_id={$this->location1->id}");

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.total'));

        // Filter by location 2 - should return 0 items
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/inventory?location_id={$this->location2->id}");

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('meta.total'));
    }

    public function test_pagination(): void
    {
        // Create 25 products with stock and location
        for ($i = 0; $i < 25; $i++) {
            Product::create([
                'company_id' => $this->company->id,
                'name' => "Product {$i}",
                'sku' => "SKU{$i}",
                'price' => 50,
                'stock' => 10,
                'location_id' => $this->location1->id,
                'published' => true,
            ]);
        }

        // Page 1 with limit 10
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory?page=1&limit=10');

        $response->assertStatus(200);
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.page'));
        $this->assertEquals(10, $response->json('meta.limit'));
        $this->assertEquals(10, count($response->json('data')));
    }

    public function test_limit_capped_at_100(): void
    {
        Product::create([
            'company_id' => $this->company->id,
            'name' => 'Product',
            'sku' => 'SKU',
            'price' => 50,
            'stock' => 10,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        // Request with limit > 100
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory?limit=200');

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('meta.limit')); // Capped at 100
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/inventory');

        $response->assertStatus(401);
    }

    public function test_company_isolation(): void
    {
        // Create another company with its product
        $otherCompany = Company::create([
            'name' => 'Other Company',
            'status' => 'active',
        ]);

        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Warehouse',
        ]);

        Product::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Product',
            'sku' => 'OTHER-SKU',
            'price' => 50,
            'stock' => 100,
            'location_id' => $otherLocation->id,
            'published' => true,
        ]);

        // Create product for our company
        Product::create([
            'company_id' => $this->company->id,
            'name' => 'Our Product',
            'sku' => 'OUR-SKU',
            'price' => 50,
            'stock' => 100,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $response->assertStatus(200);
        // Should only see our product, not the other company's
        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('Our Product', $response->json('data.0.productName'));
    }

    public function test_empty_inventory(): void
    {
        // No products created
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/inventory');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('meta.total'));
        $this->assertEmpty($response->json('data'));
    }
}
