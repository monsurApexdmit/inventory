<?php

namespace Tests\Feature\VendorReturn;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaasUser;
use App\Models\Vendor;
use App\Models\VendorReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class VendorReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private Vendor $vendor;
    private Product $product;
    private ProductVariant $variant;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();

        // Create company and owner
        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->create(['company_id' => $this->company->id]);

        // Create vendor
        $this->vendor = Vendor::factory()->create(['company_id' => $this->company->id]);

        // Create product with variant
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'stock' => 100]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $this->product->id, 'stock' => 50]);

        // Generate JWT token
        $this->token = JWTAuth::fromUser($this->owner);
    }

    public function test_list_vendor_returns(): void
    {
        VendorReturn::factory(3)->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendor-returns');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'companyId',
                        'returnNumber',
                        'vendorId',
                        'vendorName',
                        'totalAmount',
                        'status',
                        'returnDate',
                        'completedDate',
                        'creditType',
                        'notes',
                        'createdBy',
                        'createdAt',
                        'updatedAt',
                        'vendor',
                        'items',
                    ],
                ],
                'current_page',
                'total',
                'per_page',
                'last_page',
            ],
        ]);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_list_vendor_returns_with_status_filter(): void
    {
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'status' => 'pending']);
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'status' => 'completed']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendor-returns?status=pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('pending', $response->json('data.data.0.status'));
    }

    public function test_list_vendor_returns_with_vendor_id_filter(): void
    {
        $vendor2 = Vendor::factory()->create(['company_id' => $this->company->id]);

        VendorReturn::factory()->create(['company_id' => $this->company->id, 'vendor_id' => $this->vendor->id]);
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'vendor_id' => $vendor2->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/vendor-returns?vendor_id={$this->vendor->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_get_vendor_return(): void
    {
        $return = VendorReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/vendor-returns/{$return->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $return->id);
        $response->assertJsonPath('data.returnNumber', $return->return_number);
    }

    public function test_create_vendor_return_with_product(): void
    {
        $initialStock = $this->product->stock;

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendor-returns', [
                'vendorId' => $this->vendor->id,
                'vendorName' => $this->vendor->name,
                'creditType' => 'refund',
                'totalAmount' => 100.00,
                'items' => [
                    [
                        'productId' => $this->product->id,
                        'productName' => $this->product->name,
                        'quantity' => 10,
                        'unitPrice' => 50.00,
                        'totalPrice' => 500.00,
                        'unitCost' => 30.00,
                        'reason' => 'Defective batch',
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.totalAmount', 100);

        // Verify stock was deducted
        $this->product->refresh();
        $this->assertEquals($initialStock - 10, $this->product->stock);

        // Verify return item was created
        $this->assertDatabaseHas('vendor_return_items', [
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);
    }

    public function test_create_vendor_return_with_variant(): void
    {
        $initialStock = $this->variant->stock;

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendor-returns', [
                'vendorId' => $this->vendor->id,
                'vendorName' => $this->vendor->name,
                'creditType' => 'credit_note',
                'items' => [
                    [
                        'productId' => $this->product->id,
                        'productName' => $this->product->name,
                        'variantId' => $this->variant->id,
                        'variantName' => $this->variant->name,
                        'quantity' => 5,
                        'unitPrice' => 50.00,
                        'totalPrice' => 250.00,
                        'unitCost' => 30.00,
                        'reason' => 'Wrong size',
                    ],
                ],
            ]);

        $response->assertStatus(201);

        // Verify variant stock was deducted
        $this->variant->refresh();
        $this->assertEquals($initialStock - 5, $this->variant->stock);
    }

    public function test_create_vendor_return_with_insufficient_product_stock(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'stock' => 3]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendor-returns', [
                'vendorId' => $this->vendor->id,
                'vendorName' => $this->vendor->name,
                'creditType' => 'refund',
                'items' => [
                    [
                        'productId' => $product->id,
                        'productName' => $product->name,
                        'quantity' => 10,
                        'reason' => 'Defective',
                    ],
                ],
            ]);

        $response->assertStatus(400);
        // Stock should not be deducted
        $product->refresh();
        $this->assertEquals(3, $product->stock);
    }

    public function test_update_vendor_return(): void
    {
        $return = VendorReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/vendor-returns/{$return->id}", [
                'status' => 'shipped',
                'notes' => 'Updated notes',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('vendor_returns', [
            'id' => $return->id,
            'status' => 'shipped',
            'notes' => 'Updated notes',
        ]);
    }

    public function test_update_vendor_return_status_to_completed_auto_sets_date(): void
    {
        $return = VendorReturn::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'pending',
            'completed_date' => null,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/vendor-returns/{$return->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);
        $return->refresh();
        $this->assertEquals('completed', $return->status);
        $this->assertNotNull($return->completed_date);
    }

    public function test_update_vendor_return_status_via_patch(): void
    {
        $return = VendorReturn::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/vendor-returns/{$return->id}/status", [
                'status' => 'received_by_vendor',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('vendor_returns', [
            'id' => $return->id,
            'status' => 'received_by_vendor',
        ]);
    }

    public function test_delete_vendor_return(): void
    {
        $return = VendorReturn::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/vendor-returns/{$return->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Vendor return deleted successfully');
        $this->assertSoftDeleted('vendor_returns', ['id' => $return->id]);
    }

    public function test_get_vendor_return_stats(): void
    {
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'status' => 'pending', 'total_amount' => 100]);
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'status' => 'completed', 'total_amount' => 200]);
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'status' => 'shipped', 'total_amount' => 150]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendor-returns/stats');

        $response->assertStatus(200);
        $response->assertJsonPath('data.totalReturns', 3);
        $response->assertJsonPath('data.pending', 1);
        $response->assertJsonPath('data.completed', 1);
        $response->assertJsonPath('data.shipped', 1);
        $response->assertJsonPath('data.totalCreditAmount', 450);
    }

    public function test_get_vendor_returns_by_vendor(): void
    {
        $vendor2 = Vendor::factory()->create(['company_id' => $this->company->id]);

        VendorReturn::factory(2)->create(['company_id' => $this->company->id, 'vendor_id' => $this->vendor->id]);
        VendorReturn::factory()->create(['company_id' => $this->company->id, 'vendor_id' => $vendor2->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/vendor-returns/vendor/{$this->vendor->id}");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_get_vendor_return_not_found_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendor-returns/99999');

        $response->assertStatus(404);
    }

    public function test_create_vendor_return_requires_vendor_id(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendor-returns', [
                'vendorName' => 'Test Vendor',
                'creditType' => 'refund',
                'items' => [
                    [
                        'productName' => 'Product',
                        'quantity' => 1,
                        'reason' => 'Defective',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_create_vendor_return_requires_items(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendor-returns', [
                'vendorId' => $this->vendor->id,
                'vendorName' => $this->vendor->name,
                'creditType' => 'refund',
            ]);

        $response->assertStatus(422);
    }

    public function test_vendor_returns_requires_authentication(): void
    {
        $response = $this->getJson('/api/vendor-returns');

        $response->assertStatus(401);
    }

    public function test_vendor_returns_scoped_to_company(): void
    {
        $otherCompany = Company::factory()->create();
        VendorReturn::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendor-returns');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }
}
