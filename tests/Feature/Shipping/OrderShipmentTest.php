<?php

namespace Tests\Feature\Shipping;

use App\Models\Company;
use App\Models\SaasUser;
use App\Models\OrderShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class OrderShipmentTest extends TestCase
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

    public function test_list_shipments(): void
    {
        // Skip until sells table exists
        $this->markTestSkipped('Requires sells table');
    }

    public function test_create_shipment(): void
    {
        $this->markTestSkipped('Requires sells table');
    }

    public function test_update_shipment_status(): void
    {
        $this->markTestSkipped('Requires sells table');
    }

    public function test_public_tracking(): void
    {
        // Skip - requires sells table
        $this->markTestSkipped('Requires sells table');
    }

    public function test_public_tracking_not_found(): void
    {
        $response = $this->getJson('/api/track/NONEXISTENT');
        $response->assertStatus(404);
    }
}
