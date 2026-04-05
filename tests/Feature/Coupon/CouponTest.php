<?php

namespace Tests\Feature\Coupon;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private SaasUser $owner;
    private Company $company;
    private string $token;

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
    }

    public function test_list_coupons(): void
    {
        // Create 3 coupons
        for ($i = 0; $i < 3; $i++) {
            Coupon::create([
                'company_id' => $this->company->id,
                'campaign_name' => 'Campaign ' . $i,
                'code' => 'CODE' . $i,
                'discount' => 10,
                'type' => 'percentage',
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'status' => true,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/coupons?page=1&per_page=10');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'companyId',
                    'campaignName',
                    'code',
                    'discount',
                    'type',
                    'startDate',
                    'endDate',
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

        $this->assertEquals(3, count($response->json('data')));
    }

    public function test_get_coupon(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Summer Sale',
            'code' => 'SUMMER20',
            'discount' => 20,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
            'usage_limit' => 100,
            'times_used' => 5,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/coupons/{$coupon->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $coupon->id);
        $response->assertJsonPath('data.code', 'SUMMER20');
        $response->assertJsonPath('data.discount', 20);
        $response->assertJsonPath('data.timesUsed', 5);
    }

    public function test_get_coupon_not_found(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/coupons/9999');

        $response->assertStatus(404);
    }

    public function test_get_coupon_by_code_public(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Test Coupon',
            'code' => 'TESTCODE',
            'discount' => 15,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
        ]);

        // No authentication required
        $response = $this->getJson('/api/coupons/code/TESTCODE');

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'TESTCODE');
        $response->assertJsonPath('data.discount', 15);
    }

    public function test_inactive_coupon_not_returned_by_code_lookup(): void
    {
        Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Inactive Coupon',
            'code' => 'INACTIVE',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => false,  // inactive
        ]);

        $response = $this->getJson('/api/coupons/code/INACTIVE');

        $response->assertStatus(404);
    }

    public function test_create_coupon(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons', [
                'campaignName' => 'New Campaign',
                'code' => 'NEWCODE',
                'discount' => 25,
                'type' => 'percentage',
                'startDate' => now()->toDateTimeString(),
                'endDate' => now()->addDays(30)->toDateTimeString(),
                'status' => true,
                'usageLimit' => 100,
                'usageLimitPerUser' => 1,
                'minOrderAmount' => 50,
                'maxDiscount' => 25,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.campaignName', 'New Campaign');
        $response->assertJsonPath('data.code', 'NEWCODE');
        $response->assertJsonPath('data.discount', 25);

        $this->assertDatabaseHas('coupons', [
            'campaign_name' => 'New Campaign',
            'code' => 'NEWCODE',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_create_coupon_duplicate_code_returns_error(): void
    {
        Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'First Coupon',
            'code' => 'DUPLICATE',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons', [
                'campaignName' => 'Second Coupon',
                'code' => 'DUPLICATE',
                'discount' => 20,
                'type' => 'fixed',
                'startDate' => now()->toDateTimeString(),
                'endDate' => now()->addDays(30)->toDateTimeString(),
            ]);

        $response->assertStatus(400);
    }

    public function test_same_code_different_company_succeeds(): void
    {
        // Create another company
        $other = Company::create(['name' => 'Other Co', 'status' => 'active']);

        Coupon::create([
            'company_id' => $other->id,
            'campaign_name' => 'Other Coupon',
            'code' => 'SHARED',
            'discount' => 5,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
        ]);

        // Create same code for our company - should succeed
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons', [
                'campaignName' => 'Our Coupon',
                'code' => 'SHARED',
                'discount' => 15,
                'type' => 'percentage',
                'startDate' => now()->toDateTimeString(),
                'endDate' => now()->addDays(30)->toDateTimeString(),
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseCount('coupons', 2);
    }

    public function test_update_coupon(): void
    {
        $startDate = now();
        $endDate = now()->addDays(30);

        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Original',
            'code' => 'ORIG',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/coupons/{$coupon->id}", [
                'campaignName' => 'Updated Campaign',
                'discount' => 20,
                'startDate' => $startDate->toDateTimeString(),
                'endDate' => $endDate->toDateTimeString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.campaignName', 'Updated Campaign');
        $response->assertJsonPath('data.discount', 20);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'campaign_name' => 'Updated Campaign',
        ]);
    }

    public function test_delete_coupon(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'To Delete',
            'code' => 'DELETE',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/coupons/{$coupon->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_validate_percentage_coupon(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Percentage Coupon',
            'code' => 'PERCENT20',
            'discount' => 20,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
            'min_order_amount' => 50,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'PERCENT20',
                'orderAmount' => 100,
                'customerId' => 1,
                'cartItems' => [],
            ]);

        $response->assertStatus(200);
        // 20% of 100 = 20
        $this->assertArrayHasKey('discountAmount', $response->json('data'));
        $this->assertEquals(20, $response->json('data.discountAmount'));
    }

    public function test_validate_percentage_coupon_with_max_discount_cap(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Capped Coupon',
            'code' => 'CAPPED',
            'discount' => 30,  // 30%
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
            'max_discount' => 15,  // capped at 15
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'CAPPED',
                'orderAmount' => 100,  // 30% = 30, but capped at 15
            ]);

        $response->assertStatus(200);
        $this->assertEquals(15, $response->json('data.discountAmount'));
    }

    public function test_validate_fixed_coupon(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Fixed Coupon',
            'code' => 'FIXED10',
            'discount' => 10,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'FIXED10',
                'orderAmount' => 100,
            ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $response->json('data.discountAmount'));
    }

    public function test_validate_expired_coupon_returns_error(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Expired Coupon',
            'code' => 'EXPIRED',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => now()->subDays(60),
            'end_date' => now()->subDays(30),  // Already expired
            'status' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'EXPIRED',
                'orderAmount' => 100,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'COUPON_EXPIRED');
    }

    public function test_validate_min_order_amount_check(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Min Order Coupon',
            'code' => 'MINORDER',
            'discount' => 10,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
            'min_order_amount' => 100,
        ]);

        // Order below minimum
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'MINORDER',
                'orderAmount' => 50,  // Below 100 minimum
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'MIN_ORDER_AMOUNT_NOT_MET');
    }

    public function test_validate_usage_limit_exceeded(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Limited Coupon',
            'code' => 'LIMITED',
            'discount' => 10,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
            'usage_limit' => 1,
            'times_used' => 1,  // Already used 1 time (limit is 1)
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'LIMITED',
                'orderAmount' => 100,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'USAGE_LIMIT_EXCEEDED');
    }

    public function test_validate_coupon_requires_authentication(): void
    {
        $response = $this->getJson('/api/coupons');

        $response->assertStatus(401);
    }

    public function test_validate_inactive_coupon(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Inactive',
            'code' => 'INACTIVE',
            'discount' => 10,
            'type' => 'percentage',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => false,  // inactive
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/coupons/validate', [
                'code' => 'INACTIVE',
                'orderAmount' => 100,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'COUPON_INACTIVE');
    }

    public function test_get_coupon_usage_stats(): void
    {
        $coupon = Coupon::create([
            'company_id' => $this->company->id,
            'campaign_name' => 'Stats Coupon',
            'code' => 'STATS',
            'discount' => 10,
            'type' => 'fixed',
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/coupons/{$coupon->id}/usage-stats");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'total_uses',
                'total_discount_given',
                'unique_customers',
            ]
        ]);
    }
}
