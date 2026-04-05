<?php

namespace Tests\Feature\Customer;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->create(['company_id' => $this->company->id]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    public function test_list_customers(): void
    {
        Customer::factory(3)->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_list_customers_with_search(): void
    {
        Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'John Doe']);
        Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Jane Smith']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers?search=john');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_list_customers_with_status_filter(): void
    {
        Customer::factory()->active()->create(['company_id' => $this->company->id]);
        Customer::factory()->inactive()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers?status=active');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_list_customers_with_type_filter(): void
    {
        Customer::factory()->retail()->create(['company_id' => $this->company->id]);
        Customer::factory()->wholesale()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers?type=retail');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_get_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/customers/{$customer->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $customer->id);
    }

    public function test_create_customer(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customers', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '555-1234',
                'address' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'zipCode' => '10001',
                'country' => 'US',
                'customerType' => 'retail',
                'status' => 'active',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'John Doe');
        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'email' => 'john@example.com',
        ]);
        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
        ]);
    }

    public function test_create_customer_with_duplicate_email_returns_409(): void
    {
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'john@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customers', [
                'name' => 'Another Customer',
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(409);
    }

    public function test_update_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/customers/{$customer->id}", [
                'name' => 'Updated Name',
                'phone' => '555-9999',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_customer_syncs_user(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/customers/{$customer->id}", [
                'name' => 'New Name',
                'email' => 'newemail@example.com',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $customer->user_id,
            'username' => 'New Name',
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_delete_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $userId = $customer->user_id;

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertSoftDeleted('users', ['id' => $userId]);
    }

    public function test_get_customer_not_found(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers/99999');

        $response->assertStatus(404);
    }

    public function test_create_customer_requires_name(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customers', [
                'email' => 'john@example.com',
            ]);

        $response->assertStatus(422);
    }

    public function test_create_customer_requires_email(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/customers', [
                'name' => 'John Doe',
            ]);

        $response->assertStatus(422);
    }

    public function test_customers_requires_authentication(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertStatus(401);
    }

    public function test_customer_scoped_to_company(): void
    {
        $otherCompany = Company::factory()->create();
        Customer::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/customers');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }
}
