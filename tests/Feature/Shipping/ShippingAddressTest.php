<?php

namespace Tests\Feature\Shipping;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SaasUser;
use App\Models\ShippingAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ShippingAddressTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Customer $customer;
    private SaasUser $owner;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $this->owner = SaasUser::factory()->owner()->create(['company_id' => $this->company->id]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    public function test_list_shipping_addresses(): void
    {
        ShippingAddress::factory(2)->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/shipping-addresses');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_create_shipping_address(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/shipping-addresses', [
                'customerId' => $this->customer->id,
                'fullName' => 'John Doe',
                'phone' => '555-1234',
                'addressLine1' => '123 Main St',
                'city' => 'Dhaka',
                'state' => 'Dhaka',
                'postalCode' => '1200',
                'addressType' => 'home',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('shipping_addresses', [
            'company_id' => $this->company->id,
            'full_name' => 'John Doe',
        ]);
    }

    public function test_update_shipping_address(): void
    {
        $address = ShippingAddress::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/shipping-addresses/{$address->id}", [
                'fullName' => 'Jane Doe',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shipping_addresses', [
            'id' => $address->id,
            'full_name' => 'Jane Doe',
        ]);
    }

    public function test_set_default_address(): void
    {
        $address = ShippingAddress::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'is_default' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/shipping-addresses/{$address->id}/set-default");

        $response->assertStatus(200);
        $this->assertDatabaseHas('shipping_addresses', [
            'id' => $address->id,
            'is_default' => true,
        ]);
    }

    public function test_delete_shipping_address(): void
    {
        $address = ShippingAddress::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/shipping-addresses/{$address->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('shipping_addresses', ['id' => $address->id]);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/shipping-addresses');
        $response->assertStatus(401);
    }
}
