<?php

namespace Tests\Feature\Billing;

use App\Models\Company;
use App\Models\SaasUser;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $companyId;
    private SubscriptionPlan $basicPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $company = Company::factory()->create();
        $this->companyId = $company->id;

        $owner = SaasUser::factory()
            ->owner()
            ->active()
            ->forCompany($company)
            ->create();

        $this->token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($owner);
        $this->basicPlan = SubscriptionPlan::where('name', 'Basic')->first();
    }

    public function test_get_plans(): void
    {
        $response = $this->getJson('/api/billing/plans', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id', 'name', 'description', 'price', 'billingPeriod',
                    'maxUsers', 'maxProducts', 'maxBranches', 'features', 'isFeatured',
                ],
            ],
        ]);
    }

    public function test_get_subscription(): void
    {
        Subscription::factory()->create([
            'company_id' => $this->companyId,
            'plan_id' => $this->basicPlan->id,
        ]);

        $response = $this->getJson('/api/billing/subscription', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id', 'companyId', 'planId', 'status', 'nextBillingDate',
            ],
        ]);
    }

    public function test_get_subscription_when_none_exists(): void
    {
        $response = $this->getJson('/api/billing/subscription', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => ['status' => 'none'],
        ]);
    }

    public function test_get_payments(): void
    {
        $subscription = Subscription::factory()->create([
            'company_id' => $this->companyId,
            'plan_id' => $this->basicPlan->id,
        ]);

        $response = $this->getJson('/api/billing/payments', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'data']);
    }

    public function test_create_subscription(): void
    {
        $response = $this->postJson('/api/billing/create-subscription', [
            'planId' => $this->basicPlan->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->companyId,
            'plan_id' => $this->basicPlan->id,
        ]);
    }

    public function test_upgrade_subscription(): void
    {
        Subscription::factory()->create([
            'company_id' => $this->companyId,
            'plan_id' => $this->basicPlan->id,
        ]);

        $proPlan = SubscriptionPlan::where('name', 'Professional')->first();

        $response = $this->postJson('/api/billing/upgrade', [
            'planId' => $proPlan->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
    }

    public function test_cancel_subscription(): void
    {
        $subscription = Subscription::factory()->create([
            'company_id' => $this->companyId,
            'plan_id' => $this->basicPlan->id,
        ]);

        $response = $this->postJson('/api/billing/cancel', [
            'subscriptionId' => $subscription->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_get_billing_contact(): void
    {
        $response = $this->getJson('/api/billing/contact', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'companyId', 'email', 'phone', 'address', 'country',
            ],
        ]);
    }

    public function test_upsert_billing_contact(): void
    {
        $response = $this->putJson('/api/billing/contact', [
            'email' => 'billing@company.com',
            'phone' => '+1234567890',
            'address' => '123 Main St',
            'city' => 'New York',
            'country' => 'US',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('billing_contacts', [
            'company_id' => $this->companyId,
            'email' => 'billing@company.com',
        ]);
    }

    public function test_billing_requires_authentication(): void
    {
        $response = $this->getJson('/api/billing/plans');

        $response->assertStatus(401);
    }

    public function test_create_subscription_with_invalid_plan(): void
    {
        $response = $this->postJson('/api/billing/create-subscription', [
            'planId' => 999,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_upgrade_nonexistent_subscription(): void
    {
        $proPlan = SubscriptionPlan::where('name', 'Professional')->first();

        $response = $this->postJson('/api/billing/upgrade', [
            'planId' => $proPlan->id,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(404);
    }
}
