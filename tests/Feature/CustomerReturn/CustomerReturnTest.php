<?php

namespace Tests\Feature\CustomerReturn;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CustomerReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private Customer $customer;
    private Product $product;
    private ProductVariant $variant;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'stock' => 100]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $this->product->id, 'stock' => 50]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    public function test_list_customer_returns(): void
    {
        CustomerReturn::factory(3)->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customer-returns');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_list_customer_returns_with_status_filter(): void
    {
        CustomerReturn::factory()->pending()->create(['company_id' => $this->company->id]);
        CustomerReturn::factory()->approved()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customer-returns?status=pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_list_customer_returns_with_customer_filter(): void
    {
        $customer2 = Customer::factory()->create(['company_id' => $this->company->id]);
        CustomerReturn::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id]);
        CustomerReturn::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer2->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/customer-returns?customer_id={$this->customer->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_get_customer_return(): void
    {
        $return = CustomerReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/customer-returns/{$return->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $return->id);
    }

    public function test_create_customer_return(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customer-returns', [
                'customerId' => $this->customer->id,
                'customerName' => $this->customer->name,
                'totalAmount' => 50.00,
                'refundMethod' => 'original_payment',
                'items' => [
                    [
                        'productId' => $this->product->id,
                        'productName' => $this->product->name,
                        'quantity' => 2,
                        'price' => 25.00,
                        'reason' => 'Defective',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customer_returns', [
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_create_customer_return_with_variant(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customer-returns', [
                'customerId' => $this->customer->id,
                'customerName' => $this->customer->name,
                'refundMethod' => 'cash',
                'items' => [
                    [
                        'productId' => $this->product->id,
                        'variantId' => $this->variant->id,
                        'quantity' => 1,
                        'price' => 50.00,
                        'reason' => 'Wrong size',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customer_return_items', [
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
        ]);
    }

    public function test_create_return_auto_fills_product_name(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customer-returns', [
                'customerId' => $this->customer->id,
                'customerName' => 'Test Customer',
                'refundMethod' => 'store_credit',
                'items' => [
                    [
                        'productId' => $this->product->id,
                        'quantity' => 1,
                        'reason' => 'Defective',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('customer_return_items', [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
        ]);
    }

    public function test_update_customer_return(): void
    {
        $return = CustomerReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/customer-returns/{$return->id}", [
                'notes' => 'Updated notes',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('customer_returns', [
            'id' => $return->id,
            'notes' => 'Updated notes',
        ]);
    }

    public function test_approve_customer_return_restocks_variant(): void
    {
        $return = CustomerReturn::factory()->pending()->create(['company_id' => $this->company->id]);
        $item = \App\Models\CustomerReturnItem::factory()->create([
            'return_id' => $return->id,
            'variant_id' => $this->variant->id,
            'quantity' => 5,
        ]);

        $initialStock = $this->variant->inventory()->sum('quantity') ?? 0;

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/customer-returns/{$return->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($response->json('data.processedDate'));

        // Verify inventory was restocked
        $updatedStock = $this->variant->inventory()->sum('quantity') ?? 0;
        $this->assertEquals($initialStock + 5, $updatedStock);
    }

    public function test_approve_customer_return_restocks_product(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'stock' => 50]);
        $return = CustomerReturn::factory()->pending()->create(['company_id' => $this->company->id]);
        \App\Models\CustomerReturnItem::factory()->create([
            'return_id' => $return->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/customer-returns/{$return->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'approved');

        // Verify item is still there
        $this->assertDatabaseHas('customer_return_items', [
            'return_id' => $return->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_approve_non_pending_return_fails(): void
    {
        $return = CustomerReturn::factory()->approved()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/customer-returns/{$return->id}/approve");

        $response->assertStatus(400);
    }

    public function test_reject_customer_return(): void
    {
        $return = CustomerReturn::factory()->pending()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/customer-returns/{$return->id}/reject", [
                'notes' => 'Out of return window',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('customer_returns', [
            'id' => $return->id,
            'status' => 'rejected',
            'notes' => 'Out of return window',
        ]);
    }

    public function test_reject_non_pending_return_fails(): void
    {
        $return = CustomerReturn::factory()->rejected()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/customer-returns/{$return->id}/reject");

        $response->assertStatus(400);
    }

    public function test_delete_customer_return(): void
    {
        $return = CustomerReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/customer-returns/{$return->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('customer_returns', ['id' => $return->id]);
    }

    public function test_get_customer_return_stats(): void
    {
        CustomerReturn::factory()->pending()->create(['company_id' => $this->company->id, 'total_amount' => 100]);
        CustomerReturn::factory()->approved()->create(['company_id' => $this->company->id, 'total_amount' => 200]);
        CustomerReturn::factory()->rejected()->create(['company_id' => $this->company->id, 'total_amount' => 50]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customer-returns/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 3);
        $response->assertJsonPath('data.pending', 1);
        $response->assertJsonPath('data.approved', 1);
        $response->assertJsonPath('data.rejected', 1);
        $response->assertJsonPath('data.totalRefundAmount', 350);
    }

    public function test_get_customer_returns_by_customer(): void
    {
        $customer2 = Customer::factory()->create(['company_id' => $this->company->id]);

        CustomerReturn::factory(2)->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id]);
        CustomerReturn::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer2->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/customer-returns/customer/{$this->customer->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_create_return_requires_refund_method(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customer-returns', [
                'customerId' => $this->customer->id,
                'items' => [
                    [
                        'productName' => 'Test',
                        'reason' => 'Defective',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_customer_returns_requires_authentication(): void
    {
        $response = $this->getJson('/api/customer-returns');

        $response->assertStatus(401);
    }

    public function test_customer_returns_scoped_to_company(): void
    {
        $otherCompany = Company::factory()->create();
        CustomerReturn::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customer-returns');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }
}
