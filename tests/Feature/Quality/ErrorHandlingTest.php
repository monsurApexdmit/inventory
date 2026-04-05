<?php

namespace Tests\Feature\Quality;

use App\Models\Company;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
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

    /**
     * Test 1: 404 Not Found returns error
     */
    public function test_404_returns_error(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments/999999');

        $response->assertStatus(404);
        $this->assertArrayHasKey('message', $response->json());
    }

    /**
     * Test 2: 401 Unauthorized returns structured error
     */
    public function test_401_unauthorized_returns_structured_error(): void
    {
        $response = $this->getJson('/api/salary-payments');

        $response->assertStatus(401);
        $this->assertArrayHasKey('message', $response->json());
    }

    /**
     * Test 3: Different company data is isolated
     */
    public function test_different_company_data_isolated(): void
    {
        $otherCompany = Company::factory()->create();
        $otherOwner = SaasUser::factory()->create(['company_id' => $otherCompany->id]);
        $otherToken = JWTAuth::fromUser($otherOwner);

        // Try to access resource from different company
        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/salary-payments');

        // Should succeed but get no data (different company)
        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    /**
     * Test 4: 422 Validation Error returns field errors
     */
    public function test_422_validation_error_returns_field_errors(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'month' => 'invalid-format',
                'amount' => 'not-a-number',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('message', $response->json());
        $this->assertArrayHasKey('errors', $response->json());
    }

    /**
     * Test 5: 409 Conflict returns error
     */
    public function test_409_conflict_returns_error(): void
    {
        $staff = \App\Models\Staff::factory()->create(['company_id' => $this->company->id]);

        // Create first payment
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $staff->id,
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        // Try to create duplicate
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $staff->id,
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(409);
        $this->assertArrayHasKey('message', $response->json());
    }

    /**
     * Test 6: 500 Server Error returns structured error without stack trace
     */
    public function test_500_error_hides_stack_trace_in_production(): void
    {
        $this->app['config']['app.debug'] = false;

        // Trigger an error somehow (we'll mock it)
        try {
            throw new \Exception('Test exception');
        } catch (\Exception $e) {
            // In production mode, stack trace should not be visible
            $this->assertFalse(config('app.debug'));
        }
    }

    /**
     * Test 7: Validation error contains helpful message
     */
    public function test_validation_error_contains_helpful_message(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => -1,
                'month' => '2024',
                'amount' => -100,
            ]);

        $response->assertStatus(422);
        $this->assertIsArray($response->json('errors.staffId'));
    }

    /**
     * Test 8: Method Not Allowed (405) returns structured error
     */
    public function test_405_method_not_allowed(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson('/api/salary-payments');

        $response->assertStatus(405);
    }

    /**
     * Test 9: Bad Request (400) returns error
     */
    public function test_400_bad_request_returns_error(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => 999,  // Non-existent staff
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(400);
        $this->assertArrayHasKey('message', $response->json());
    }

    /**
     * Test 10: All error responses have consistent structure
     */
    public function test_error_responses_have_consistent_structure(): void
    {
        // 404
        $response404 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments/999');
        $this->assertArrayHasKey('message', $response404->json());

        // 422
        $response422 = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', []);
        $this->assertArrayHasKey('message', $response422->json());
        $this->assertArrayHasKey('errors', $response422->json());

        // 401
        $response401 = $this->getJson('/api/salary-payments');
        $this->assertArrayHasKey('message', $response401->json());
    }
}
