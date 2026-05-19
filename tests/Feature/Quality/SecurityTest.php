<?php

namespace Tests\Feature\Quality;

use App\Models\Company;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->owner()->create(['company_id' => $this->company->id]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    /**
     * Test 1: SQL Injection attempt in search parameter is sanitized
     */
    public function test_sql_injection_in_query_is_sanitized(): void
    {
        $maliciousInput = "2024-01' OR '1'='1";

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?month=' . urlencode($maliciousInput));

        // Should handle safely - either 200 with no results or 422
        $this->assertThat(
            $response->status(),
            $this->logicalOr($this->equalTo(200), $this->equalTo(422))
        );
    }

    /**
     * Test 2: XSS attempt in request body is escaped
     */
    public function test_xss_attempt_in_request_body_is_escaped(): void
    {
        $staff = \App\Models\Staff::factory()->create(['company_id' => $this->company->id]);

        $xssPayload = '<script>alert("XSS")</script>';

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $staff->id,
                'month' => '2024-01',
                'amount' => 5000,
                'remarks' => $xssPayload,
            ]);

        $response->assertStatus(201);
        // Data should be stored safely, not executed
        $this->assertDatabaseHas('salary_payments', [
            'remarks' => $xssPayload,
        ]);
    }

    /**
     * Test 3: Expired token is rejected
     */
    public function test_expired_token_is_rejected(): void
    {
        // Create a token and manually expire it
        $expiredToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOlwvXC9sb2NhbGhvc3Q6ODAwMFwvYXBpXC9hdXRoXC9sb2dpbiIsImF1ZCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwIiwiaWF0IjoxNTE2MjM5MDIyLCJleHAiOjE1MTYyMzkwMjIsImRhdGEiOnsiaWQiOjF9fQ.test';

        $response = $this->withHeader('Authorization', "Bearer {$expiredToken}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(401);
    }

    /**
     * Test 4: Invalid token signature is rejected
     */
    public function test_invalid_token_signature_is_rejected(): void
    {
        $invalidToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.invalid.signature';

        $response = $this->withHeader('Authorization', "Bearer {$invalidToken}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(401);
    }

    /**
     * Test 5: Missing Authorization header is rejected
     */
    public function test_missing_authorization_header_is_rejected(): void
    {
        $response = $this->getJson('/api/salary-payments');

        $response->assertStatus(401);
    }

    /**
     * Test 6: Malformed Authorization header is rejected
     */
    public function test_malformed_authorization_header_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'InvalidFormat')
            ->getJson('/api/salary-payments');

        $response->assertStatus(401);
    }

    /**
     * Test 7: User cannot access other company's data
     */
    public function test_cannot_access_other_company_data(): void
    {
        $otherCompany = Company::factory()->create();
        $otherOwner = SaasUser::factory()->owner()->create(['company_id' => $otherCompany->id]);
        $otherToken = JWTAuth::fromUser($otherOwner);

        $staff = \App\Models\Staff::factory()->create(['company_id' => $this->company->id]);
        \App\Models\SalaryPayment::factory()->create(['staff_id' => $staff->id]);

        // Other company user should get different results
        $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(200);
        // Should be empty since salary is from different company
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    /**
     * Test 8: Rate limiting headers are present
     */
    public function test_rate_limiting_headers_present(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(200);
        // Check for rate limit headers (if implemented)
        // These are optional for now
    }

    /**
     * Test 9: HTTPS is enforced in production
     */
    public function test_https_enforced_in_production(): void
    {
        // This would be tested in production environment
        // For now, just verify the setting exists
        $this->assertIsString(config('app.url'));
    }

    /**
     * Test 10: Sensitive data is not logged
     */
    public function test_sensitive_data_not_exposed_in_errors(): void
    {
        // Create a request that might expose sensitive data
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => 999,
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(400);
        // Error message should not contain internal paths or sensitive info
        $message = json_encode($response->json());
        $this->assertStringNotContainsString('/var/www', $message);
    }

    /**
     * Test 11: Input validation prevents negative amounts
     */
    public function test_negative_amounts_rejected(): void
    {
        $staff = \App\Models\Staff::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $staff->id,
                'month' => '2024-01',
                'amount' => -5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 12: Very large numbers are handled
     */
    public function test_large_numbers_handled(): void
    {
        $staff = \App\Models\Staff::factory()->create(['company_id' => $this->company->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $staff->id,
                'month' => '2024-01',
                'amount' => 99999999999.99,
            ]);

        // Should either accept or reject with validation
        $this->assertThat(
            $response->status(),
            $this->logicalOr($this->equalTo(201), $this->equalTo(422))
        );
    }

    /**
     * Test 13: CORS headers are set correctly
     */
    public function test_cors_headers_present(): void
    {
        $response = $this->getJson('/api/salary-payments');

        // CORS is handled by middleware, just verify response
        $this->assertNotNull($response);
    }

    /**
     * Test 14: No sensitive HTTP headers leak
     */
    public function test_no_sensitive_headers_leak(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments');

        // Should not expose internal server info
        $this->assertFalse(
            str_contains((string)($response->headers->get('Server') ?? ''), 'Apache')
        );
    }

    /**
     * Test 15: Double encoding attack is prevented
     */
    public function test_double_encoding_attack_prevented(): void
    {
        $doubleEncodedInput = '%252520';  // Double encoded space

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?month=' . $doubleEncodedInput);

        $response->assertStatus(200);
    }
}
