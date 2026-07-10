<?php

namespace Tests\Feature\Vendor;

use App\Models\Company;
use App\Models\SaasUser;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class VendorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();

        // Create company and owner
        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->owner()->create(['company_id' => $this->company->id]);

        // Generate JWT token
        $this->token = JWTAuth::fromUser($this->owner);
    }

    public function test_list_vendors(): void
    {
        Vendor::factory(3)->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendors');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'companyId',
                        'userId',
                        'name',
                        'email',
                        'phone',
                        'address',
                        'logo',
                        'status',
                        'description',
                        'totalPaid',
                        'amountPayable',
                        'createdAt',
                        'updatedAt',
                        'user',
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

    public function test_list_vendors_with_search_filter(): void
    {
        Vendor::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Acme Supplier',
            'email' => 'acme@example.com',
        ]);

        Vendor::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Other Vendor',
            'email' => 'other@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendors?search=acme');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Acme Supplier', $response->json('data.data.0.name'));
    }

    public function test_list_vendors_with_status_filter(): void
    {
        Vendor::factory()->create(['company_id' => $this->company->id, 'status' => 'Active']);
        Vendor::factory()->create(['company_id' => $this->company->id, 'status' => 'Inactive']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendors?status=Active');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Active', $response->json('data.data.0.status'));
    }

    public function test_get_vendor(): void
    {
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/vendors/{$vendor->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $vendor->id);
        $response->assertJsonPath('data.name', $vendor->name);
        $response->assertJsonPath('data.email', $vendor->email);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'companyId',
                'userId',
                'name',
                'email',
                'phone',
                'address',
                'logo',
                'status',
                'description',
                'totalPaid',
                'amountPayable',
                'createdAt',
                'updatedAt',
                'user',
            ],
        ]);
    }

    public function test_create_vendor(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendors', [
                'name' => 'New Vendor',
                'email' => 'vendor@example.com',
                'phone' => '555-1234',
                'address' => '123 Supplier St',
                'status' => 'Active',
                'description' => 'A new vendor',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'New Vendor');
        $response->assertJsonPath('data.email', 'vendor@example.com');
        // Verify vendor was created
        $this->assertDatabaseHas('vendors', [
            'company_id' => $this->company->id,
            'name' => 'New Vendor',
            'email' => 'vendor@example.com',
        ]);
        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'vendor@example.com',
        ]);
    }

    public function test_create_vendor_with_duplicate_email_returns_409(): void
    {
        Vendor::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'vendor@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendors', [
                'name' => 'Another Vendor',
                'email' => 'vendor@example.com',
            ]);

        $response->assertStatus(409);
    }

    public function test_update_vendor(): void
    {
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/vendors/{$vendor->id}", [
                'name' => 'Updated Vendor',
                'phone' => '555-9999',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Updated Vendor');
        // Verify vendor was updated
        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'Updated Vendor',
            'phone' => '555-9999',
        ]);
        // Verify user was synced
        $this->assertDatabaseHas('users', [
            'id' => $vendor->user_id,
            'username' => 'Updated Vendor',
        ]);
    }

    public function test_update_vendor_email_syncs_to_user(): void
    {
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/vendors/{$vendor->id}", [
                'email' => 'newemail@example.com',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $vendor->user_id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_delete_vendor(): void
    {
        $vendor = Vendor::factory()->create(['company_id' => $this->company->id]);
        $userId = $vendor->user_id;

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/vendors/{$vendor->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Vendor deleted successfully');
        // Verify soft delete
        $this->assertSoftDeleted('vendors', ['id' => $vendor->id]);
        // Verify linked user was also soft-deleted
        $this->assertSoftDeleted('users', ['id' => $userId]);
    }

    public function test_get_vendor_not_found_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendors/99999');

        $response->assertStatus(404);
    }

    public function test_create_vendor_without_name_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendors', [
                'email' => 'vendor@example.com',
            ]);

        $response->assertStatus(422);
    }

    public function test_create_vendor_without_email_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/vendors', [
                'name' => 'New Vendor',
            ]);

        $response->assertStatus(422);
    }

    public function test_vendors_requires_authentication(): void
    {
        $response = $this->getJson('/api/vendors');

        $response->assertStatus(401);
    }

    public function test_vendor_scoped_to_company(): void
    {
        $otherCompany = Company::factory()->create();
        Vendor::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/vendors');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }
}
