<?php

namespace Tests\Feature\StockTransfer;

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

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private SaasUser $owner;
    private Company $company;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create company and owner
        $this->company = Company::create([
            'name' => 'Test Company',
            'status' => 'active',
        ]);

        $this->owner = SaasUser::create([
            'email' => 'owner@test.com',
            'full_name' => 'Test Owner',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->token = JWTAuth::fromUser($this->owner);
    }

    /**
     * Test: List all transfers with pagination
     */
    public function test_list_transfers_with_pagination(): void
    {
        // Create locations
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        // Create product
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP001',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        // Create transfers
        for ($i = 0; $i < 15; $i++) {
            StockTransfer::create([
                'company_id' => $this->company->id,
                'product_id' => $product->id,
                'from_location_id' => $loc1->id,
                'to_location_id' => $loc2->id,
                'quantity' => 1,
                'status' => 'Completed',
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/transfers?page=1&per_page=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'companyId',
                    'productId',
                    'fromLocationId',
                    'toLocationId',
                    'quantity',
                    'status',
                    'createdAt',
                    'updatedAt',
                ]
            ],
            'meta' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
            ]
        ]);

        $this->assertEquals(15, $response->json('meta.total'));
        $this->assertEquals(10, count($response->json('data')));
    }

    /**
     * Test: Filter transfers by status
     */
    public function test_filter_transfers_by_status(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP002',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        // Create completed transfers
        StockTransfer::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'from_location_id' => $loc1->id,
            'to_location_id' => $loc2->id,
            'quantity' => 10,
            'status' => 'Completed',
        ]);

        StockTransfer::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'from_location_id' => $loc1->id,
            'to_location_id' => $loc2->id,
            'quantity' => 5,
            'status' => 'Cancelled',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/transfers?status=Completed');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertEquals('Completed', $response->json('data.0.status'));
    }

    /**
     * Test: Create simple product transfer
     */
    public function test_create_simple_product_transfer(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP003',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 20,
                'notes' => 'Test transfer',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message', 'Transfer created successfully');
        $response->assertJsonPath('data.status', 'Completed');
        $response->assertJsonPath('data.quantity', 20);

        // Verify stock remains 100 (now at both locations: 80 + 20)
        $product->refresh();
        $this->assertEquals(100, $product->stock);
        // Product should be at destination now (has 20 there, 80 at source)
        $this->assertEquals($loc2->id, $product->location_id);
    }

    /**
     * Test: Simple product transfer with full stock deduction (location reassignment)
     */
    public function test_simple_product_transfer_with_full_stock_moves_location(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP004',
            'price' => 100,
            'stock' => 50,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 50,
            ]);

        $response->assertStatus(201);

        // Verify product moved to destination location with full stock transferred
        $product->refresh();
        $this->assertEquals($loc2->id, $product->location_id);
        $this->assertEquals(50, $product->stock);  // Stock remains 50, moved to destination
    }

    /**
     * Test: Insufficient stock error on simple product transfer
     */
    public function test_simple_product_transfer_insufficient_stock(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP005',
            'price' => 100,
            'stock' => 30,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 50,
            ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Insufficient stock for transfer');
    }

    /**
     * Test: Create variant product transfer
     */
    public function test_create_variant_product_transfer(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Shirt',
            'sku' => 'SHIRT001',
            'price' => 50,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Small / Red',
            'sku' => 'SHIRT001-SR',
            'price' => 50,
            'stock' => 0,
            'attributes' => json_encode(['size' => 'Small', 'color' => 'Red']),
        ]);

        // Create variant inventory at source
        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $loc1->id,
            'quantity' => 25,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'variantId' => $variant->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 10,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.variantId', $variant->id);
        $response->assertJsonPath('data.quantity', 10);

        // Verify inventory was moved
        $sourceInv = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $loc1->id)
            ->first();
        $destInv = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $loc2->id)
            ->first();

        $this->assertEquals(15, $sourceInv->quantity);
        $this->assertEquals(10, $destInv->quantity);

        // Verify variant stock was synced
        $variant->refresh();
        $this->assertEquals(25, $variant->stock);
    }

    /**
     * Test: Variant transfer with fallback to product stock
     */
    public function test_variant_transfer_fallback_to_product_stock(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        // Product with stock but no variant_inventory
        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Jacket',
            'sku' => 'JCK001',
            'price' => 150,
            'stock' => 40,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Medium / Blue',
            'sku' => 'JCK001-MB',
            'price' => 150,
            'stock' => 40,
        ]);

        // No variant_inventory record yet — should fallback to product.stock
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'variantId' => $variant->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 15,
            ]);

        $response->assertStatus(201);

        // Verify variant_inventory was created via fallback
        $sourceInv = VariantInventory::where('variant_id', $variant->id)
            ->where('location_id', $loc1->id)
            ->first();

        $this->assertNotNull($sourceInv);
        $this->assertEquals(25, $sourceInv->quantity);
    }

    /**
     * Test: Insufficient variant stock error
     */
    public function test_variant_transfer_insufficient_stock(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Hat',
            'sku' => 'HAT001',
            'price' => 25,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Large',
            'sku' => 'HAT001-L',
            'price' => 25,
            'stock' => 0,
        ]);

        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $loc1->id,
            'quantity' => 5,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'variantId' => $variant->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 10,
            ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Insufficient stock in source location');
    }

    /**
     * Test: Cancel completed simple product transfer
     */
    public function test_cancel_simple_product_transfer(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP006',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        // Create actual transfer (not just the record)
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 30,
            ]);

        $response->assertStatus(201);
        $transferId = $response->json('data.id');

        // Get the transfer to cancel
        $transfer = StockTransfer::find($transferId);

        // Now cancel it
        $cancelResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/transfers/{$transfer->id}/cancel");

        $cancelResponse->assertStatus(200);
        $cancelResponse->assertJsonPath('data.status', 'Cancelled');

        // Verify stock was reversed - should be back at source location with full amount
        $product->refresh();
        $this->assertEquals($loc1->id, $product->location_id);
        $this->assertEquals(100, $product->stock);
    }

    /**
     * Test: Cancel variant product transfer
     */
    public function test_cancel_variant_product_transfer(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Shoe',
            'sku' => 'SHOE001',
            'price' => 80,
            'stock' => 0,
            'published' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Size 10',
            'sku' => 'SHOE001-10',
            'price' => 80,
            'stock' => 0,
        ]);

        // Create variant inventories with more stock at destination
        // so we can verify the cancellation properly
        $sourceInv = VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $loc1->id,
            'quantity' => 20,
        ]);

        $destInv = VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $loc2->id,
            'quantity' => 25,
        ]);

        $transfer = StockTransfer::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'from_location_id' => $loc1->id,
            'to_location_id' => $loc2->id,
            'quantity' => 10,
            'status' => 'Completed',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/transfers/{$transfer->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'Cancelled');

        // Verify inventory was reversed
        $sourceInv->refresh();
        $destInv->refresh();

        $this->assertEquals(30, $sourceInv->quantity);
        $this->assertEquals(15, $destInv->quantity);
    }

    /**
     * Test: Cannot cancel non-completed transfer
     */
    public function test_cannot_cancel_non_completed_transfer(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP007',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $transfer = StockTransfer::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'from_location_id' => $loc1->id,
            'to_location_id' => $loc2->id,
            'quantity' => 20,
            'status' => 'Cancelled',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/transfers/{$transfer->id}/cancel");

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Only completed transfers can be cancelled');
    }

    /**
     * Test: Same location validation
     */
    public function test_transfer_same_source_and_destination_location(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'sku' => 'TP008',
            'price' => 100,
            'stock' => 100,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => $product->id,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc1->id,
                'quantity' => 20,
            ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'From location and to location must be different');
    }

    /**
     * Test: Get products by location
     */
    public function test_get_products_by_location(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $product1 = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Product A',
            'sku' => 'PA001',
            'price' => 100,
            'stock' => 50,
            'location_id' => $loc1->id,
            'published' => true,
        ]);

        $product2 = Product::create([
            'company_id' => $this->company->id,
            'name' => 'Product B',
            'sku' => 'PB001',
            'price' => 200,
            'stock' => 0,
            'location_id' => null,
            'published' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/transfers/products-by-location/{$loc1->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'sku',
                ]
            ],
            'meta' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
            ]
        ]);

        // Should contain the product with stock
        $this->assertGreaterThan(0, count($response->json('data')));
    }

    /**
     * Test: Requires authentication
     */
    public function test_transfers_require_authentication(): void
    {
        $response = $this->getJson('/api/transfers');
        $response->assertStatus(401);
    }

    /**
     * Test: Transfer not found returns 404
     */
    public function test_transfer_not_found(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/transfers/9999/cancel');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Transfer not found');
    }

    /**
     * Test: Invalid product validation
     */
    public function test_transfer_invalid_product_id(): void
    {
        $loc1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse A',
        ]);

        $loc2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Warehouse B',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'productId' => 9999,
                'fromLocationId' => $loc1->id,
                'toLocationId' => $loc2->id,
                'quantity' => 10,
            ]);

        // Validation fails because product doesn't exist (exists:products,id rule)
        $response->assertStatus(422);
        $response->assertJsonPath('errors.product_id.0', 'The selected product id is invalid.');
    }

    /**
     * Test: Validation required fields
     */
    public function test_transfer_validation_required_fields(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/transfers', [
                'quantity' => 10,
            ]);

        $response->assertStatus(422);
        // Errors are arrays of strings, not booleans
        $this->assertIsArray($response->json('errors.product_id'));
        $this->assertIsArray($response->json('errors.from_location_id'));
        $this->assertIsArray($response->json('errors.to_location_id'));
    }
}
