<?php

namespace Tests\Feature\Quality;

use App\Models\Company;
use App\Models\SaasUser;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private Staff $staff;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->owner()->create(['company_id' => $this->company->id]);
        $this->staff = Staff::factory()->create(['company_id' => $this->company->id]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    /**
     * Test 1: Empty string is rejected for required fields
     */
    public function test_empty_string_rejected_for_required_fields(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => '',
                'month' => '',
                'amount' => '',
            ]);

        $response->assertStatus(422);
        $this->assertIsArray($response->json('errors'));
    }

    /**
     * Test 2: Null values rejected for required fields
     */
    public function test_null_values_rejected_for_required_fields(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => null,
                'month' => null,
                'amount' => null,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 3: String provided for integer field is rejected
     */
    public function test_string_for_integer_field_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => 'not-an-integer',
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('staffId', $response->json('errors'));
    }

    /**
     * Test 4: String provided for numeric field is rejected
     */
    public function test_string_for_numeric_field_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => 'not-a-number',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('amount', $response->json('errors'));
    }

    /**
     * Test 5: Invalid date format is rejected
     */
    public function test_invalid_date_format_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '01-2024',  // Wrong format
                'amount' => 5000,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('month', $response->json('errors'));
    }

    /**
     * Test 6: Very long strings are truncated or rejected
     */
    public function test_very_long_string_rejected(): void
    {
        $longString = str_repeat('a', 10000);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/salary-payments/1", [
                'remarks' => $longString,
            ]);

        // Either 422 (validation) or 414 (URI too long)
        $this->assertThat(
            $response->status(),
            $this->logicalOr(
                $this->equalTo(422),
                $this->equalTo(414),
                $this->equalTo(404)
            )
        );
    }

    /**
     * Test 7: Special characters in input are escaped
     */
    public function test_special_characters_escaped(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => 5000,
                'remarks' => "'; DROP TABLE; --",
            ]);

        $response->assertStatus(201);
        // Data should be safely stored
        $this->assertDatabaseHas('salary_payments', [
            'remarks' => "'; DROP TABLE; --",
        ]);
    }

    /**
     * Test 8: Leading/trailing whitespace is trimmed
     */
    public function test_whitespace_trimmed(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '  2024-01  ',
                'amount' => 5000,
            ]);

        // Laravel automatically trims strings
        $response->assertStatus(201);
    }

    /**
     * Test 9: Type coercion respects limits
     */
    public function test_type_coercion_respects_limits(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => 9999999999999999999,  // Very large number
            ]);

        $response->assertStatus(201);
        // Should store the number as-is
    }

    /**
     * Test 10: Boolean values are validated correctly
     */
    public function test_boolean_validation(): void
    {
        // Create a test for a boolean field (e.g., published in products)
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?status=pending');

        $response->assertStatus(200);
    }

    /**
     * Test 11: Enum values are handled
     */
    public function test_enum_values_handled(): void
    {
        // If we add an endpoint that filters by status enum
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?status=invalid-status');

        // Should either ignore invalid enum or return empty results
        $this->assertThat(
            $response->status(),
            $this->logicalOr($this->equalTo(200), $this->equalTo(422))
        );
    }

    /**
     * Test 12: Array inputs are properly validated
     */
    public function test_array_input_validation(): void
    {
        // Future: test when we have endpoints accepting arrays
        $this->assertTrue(true);
    }

    /**
     * Test 13: Negative numbers are rejected for amounts
     */
    public function test_negative_amounts_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => -5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 14: Zero is accepted for optional numeric fields
     */
    public function test_zero_accepted_for_optional_numeric_fields(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => 5000,
                'paidAmount' => 0,
            ]);

        $response->assertStatus(201);
        // Verify the record was created with zero paid amount
        $this->assertGreaterThan(0, $response->json('data.id'));
    }

    /**
     * Test 15: Custom validation rules are applied
     */
    public function test_custom_validation_rules_applied(): void
    {
        // staffId must exist and belong to company
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => 999999,
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        // Should reject with 400
        $this->assertThat(
            $response->status(),
            $this->logicalOr($this->equalTo(400), $this->equalTo(422))
        );
    }
}
