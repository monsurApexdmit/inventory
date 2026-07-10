<?php

namespace Tests\Feature\Sell;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaasUser;
use App\Models\Sell;
use App\Models\ShippingAddress;
use App\Models\VariantInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class SellTest extends TestCase
{
    use RefreshDatabase;

    private SaasUser $owner;
    private Company $company;
    private string $token;
    private Location $location1;
    private Location $location2;
    private Customer $customer;
    private Product $product;

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
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->token = JWTAuth::fromUser($this->owner);

        $this->location1 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
        ]);

        $this->location2 = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Branch Store',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
        ]);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name' => 'T-Shirt',
            'sku' => 'TSHIRT-001',
            'price' => 29.99,
            'cost_price' => 15.00,
            'stock' => 100,
            'location_id' => $this->location1->id,
            'published' => true,
        ]);
    }

    public function test_create_sell_with_simple_product(): void
    {
        $payload = [
            'customerName' => 'Jane Smith',
            'amount' => 99.99,
            'method' => 'Card',
            'status' => 'Pending',
            'items' => [
                [
                    'productId' => $this->product->id,
                    'productName' => 'T-Shirt',
                    'quantity' => 2,
                    'unitPrice' => 49.99,
                ]
            ]
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals('Jane Smith', $data['customerName']);
        $this->assertTrue($data['stockDeducted']);

        // Verify stock was deducted
        $this->product->refresh();
        $this->assertEquals(98, $this->product->stock);
    }

    public function test_create_sell_with_variant_product(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Small/Red',
            'sku' => 'TSHIRT-S-R',
            'price' => 29.99,
            'cost_price' => 15.00,
            'stock' => 50,  // Set stock to match inventory
        ]);

        VariantInventory::create([
            'variant_id' => $variant->id,
            'location_id' => $this->location1->id,
            'quantity' => 50,
        ]);

        $payload = [
            'customerName' => 'Jane Smith',
            'amount' => 99.99,
            'method' => 'Card',
            'items' => [
                [
                    'productId' => $this->product->id,
                    'variantId' => $variant->id,
                    'productName' => 'T-Shirt',
                    'variantName' => 'Small/Red',
                    'quantity' => 3,
                    'unitPrice' => 29.99,
                ]
            ]
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        // Debug: if status is not 201, print the error
        if ($response->status() !== 201) {
            \Log::error('Variant test failed', ['response' => $response->json()]);
        }

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals(3, $data['items'][0]['quantity']);

        // Verify variant inventory was deducted
        $variant->refresh();
        $inventory = VariantInventory::where('variant_id', $variant->id)->first();
        $this->assertEquals(47, $inventory->quantity);
    }

    public function test_create_sell_insufficient_stock(): void
    {
        $payload = [
            'customerName' => 'Jane Smith',
            'amount' => 999.99,
            'items' => [
                [
                    'productId' => $this->product->id,
                    'productName' => 'T-Shirt',
                    'quantity' => 200, // More than available (100)
                    'unitPrice' => 29.99,
                ]
            ]
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        $response->assertStatus(400);
        $this->assertStringContainsString('Insufficient stock', $response->json('message'));
    }

    public function test_create_sell_auto_generates_invoice_number(): void
    {
        $payload = [
            'customerName' => 'Jane Smith',
            'amount' => 99.99,
            'items' => []
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertNotEmpty($data['invoiceNo']);
        $this->assertStringStartsWith('INV-', $data['invoiceNo']);
    }

    public function test_create_sell_requires_customer_name(): void
    {
        $payload = [
            'amount' => 99.99,
            'items' => []
        ];

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        $response->assertStatus(422);
    }

    public function test_list_sells_with_pagination(): void
    {
        // Create multiple sells
        for ($i = 0; $i < 15; $i++) {
            Sell::create([
                'company_id' => $this->company->id,
                'invoice_no' => 'INV-' . ($i + 1000),
                'customer_name' => 'Customer ' . $i,
                'order_time' => now(),
                'amount' => 99.99,
                'status' => 'Pending',
                'stock_deducted' => false,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/sells?per_page=10');

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(15, $response->json('meta.pagination.total'));
    }

    public function test_list_sells_with_limit(): void
    {
        // Create sells
        for ($i = 0; $i < 15; $i++) {
            Sell::create([
                'company_id' => $this->company->id,
                'invoice_no' => 'INV-' . ($i + 1000),
                'customer_name' => 'Customer ' . $i,
                'order_time' => now(),
                'amount' => 99.99,
                'status' => 'Pending',
                'stock_deducted' => false,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/sells?limit=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertNull($response->json('meta.pagination'));
    }

    public function test_list_sells_filter_by_status(): void
    {
        Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-1001',
            'customer_name' => 'Customer 1',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-1002',
            'customer_name' => 'Customer 2',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Processing',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/sells?status=Pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('Pending', $response->json('data.0.status'));
    }

    public function test_get_sell_by_id(): void
    {
        $sell = Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-2001',
            'customer_name' => 'Test Customer',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/sells/{$sell->id}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($sell->id, $data['id']);
        $this->assertEquals('Test Customer', $data['customerName']);
    }

    public function test_get_sell_by_invoice_number(): void
    {
        $sell = Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-UNIQUE-001',
            'customer_name' => 'Test Customer',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/sells/invoice/INV-UNIQUE-001");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($sell->id, $data['id']);
    }

    public function test_update_sell(): void
    {
        $sell = Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-3001',
            'customer_name' => 'Original Name',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/sells/{$sell->id}", [
                'customerName' => 'Updated Name',
                'status' => 'Processing',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('Updated Name', $data['customerName']);
        $this->assertEquals('Processing', $data['status']);
    }

    public function test_update_status_only(): void
    {
        $sell = Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-4001',
            'customer_name' => 'Test Customer',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/sells/{$sell->id}/status", [
                'status' => 'Delivered',
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals('Delivered', $data['status']);
    }

    public function test_delete_sell_restores_stock(): void
    {
        // Create and make a sell with stock deduction
        $payload = [
            'customerName' => 'Jane Smith',
            'amount' => 99.99,
            'method' => 'Card',
            'status' => 'Pending',
            'items' => [
                [
                    'productId' => $this->product->id,
                    'productName' => 'T-Shirt',
                    'quantity' => 5,
                    'unitPrice' => 49.99,
                ]
            ]
        ];

        $createResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/sells', $payload);

        $sell = Sell::find($createResponse->json('data.id'));

        // Verify stock was deducted
        $this->product->refresh();
        $this->assertEquals(95, $this->product->stock);

        // Delete sell
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/sells/{$sell->id}");

        $response->assertStatus(200);

        // Verify stock was restored
        $this->product->refresh();
        $this->assertEquals(100, $this->product->stock);
    }

    public function test_get_stats(): void
    {
        // Create sells
        Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-6001',
            'customer_name' => 'Customer 1',
            'order_time' => now(),
            'amount' => 100.00,
            'total_cost' => 50.00,
            'gross_profit' => 50.00,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-6002',
            'customer_name' => 'Customer 2',
            'order_time' => now(),
            'amount' => 200.00,
            'total_cost' => 100.00,
            'gross_profit' => 100.00,
            'status' => 'Processing',
            'stock_deducted' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/sells/stats');

        $response->assertStatus(200);
        $stats = $response->json('data');
        $this->assertEquals(2, $stats['totalSells']);
        $this->assertEquals(300.00, $stats['totalRevenue']);
        $this->assertEquals(150.00, $stats['totalCost']);
        $this->assertEquals(150.00, $stats['grossProfit']);
    }

    public function test_company_isolation(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Company',
            'status' => 'active',
        ]);

        $otherOwner = SaasUser::create([
            'email' => 'other@test.com',
            'full_name' => 'Other Owner',
            'password' => bcrypt('password'),
            'company_id' => $otherCompany->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $sell = Sell::create([
            'company_id' => $this->company->id,
            'invoice_no' => 'INV-7001',
            'customer_name' => 'Test Customer',
            'order_time' => now(),
            'amount' => 99.99,
            'status' => 'Pending',
            'stock_deducted' => false,
        ]);

        $otherToken = JWTAuth::fromUser($otherOwner);

        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/api/sells/{$sell->id}");

        $response->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/sells');

        $response->assertStatus(401);
    }
}
