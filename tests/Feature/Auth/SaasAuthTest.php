<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\SaasUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: SaaS Signup - Create new account
     * Expected: 201 Created with unverified status
     */
    public function test_saas_signup_creates_account_with_unverified_status(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'companyName'   => 'Acme Corporation',
            'ownerFullName' => 'John Doe',
            'email'         => 'john@acme.com',
            'phone'         => '+1234567890',
            'password'      => 'SecurePassword123',
            'businessType'  => 'Retail',
            'website'       => 'https://acme.com',
            'country'       => 'US',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Account created successfully. Please check your email to verify your account.',
            'data' => [
                'email'  => 'john@acme.com',
                'status' => 'unverified',
            ],
        ]);

        // Verify database
        $this->assertDatabaseHas('companies', [
            'name'   => 'Acme Corporation',
            'status' => 'trial',
        ]);

        $this->assertDatabaseHas('saas_users', [
            'email'  => 'john@acme.com',
            'status' => 'unverified',
            'role'   => 'owner',
        ]);
    }

    /**
     * Test: SaaS Signup - Duplicate email should fail
     * Expected: 409 Conflict
     */
    public function test_saas_signup_fails_with_duplicate_email(): void
    {
        // Create first user
        $this->postJson('/api/auth/signup', [
            'companyName'   => 'Company One',
            'ownerFullName' => 'John Doe',
            'email'         => 'duplicate@company.com',
            'phone'         => '+1234567890',
            'password'      => 'SecurePassword123',
        ]);

        // Try to create with same email
        $response = $this->postJson('/api/auth/signup', [
            'companyName'   => 'Company Two',
            'ownerFullName' => 'Jane Doe',
            'email'         => 'duplicate@company.com',
            'phone'         => '+0987654321',
            'password'      => 'SecurePassword123',
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'message' => 'Email already registered.',
        ]);
    }

    /**
     * Test: SaaS Signup - Missing required fields
     * Expected: 422 Unprocessable Entity
     */
    public function test_saas_signup_fails_with_missing_required_fields(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'companyName' => 'Acme',
            // Missing ownerFullName, email, phone, password
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'success',
            'message',
            'errors' => ['ownerFullName', 'email', 'phone', 'password'],
        ]);
    }

    /**
     * Test: SaaS Signup - Invalid email format
     * Expected: 422 Unprocessable Entity
     */
    public function test_saas_signup_fails_with_invalid_email(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'companyName'   => 'Acme',
            'ownerFullName' => 'John Doe',
            'email'         => 'invalid-email-format',
            'phone'         => '+1234567890',
            'password'      => 'SecurePassword123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    /**
     * Test: SaaS Signup - Password too short
     * Expected: 422 Unprocessable Entity
     */
    public function test_saas_signup_fails_with_short_password(): void
    {
        $response = $this->postJson('/api/auth/signup', [
            'companyName'   => 'Acme',
            'ownerFullName' => 'John Doe',
            'email'         => 'john@acme.com',
            'phone'         => '+1234567890',
            'password'      => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    /**
     * Test: SaaS Resend Verification - Always returns 200 (no email enumeration)
     * Expected: 200 OK
     */
    public function test_resend_verification_returns_200_for_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/resend-verification', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If that email is registered and unverified, a new verification link has been sent.',
        ]);
    }

    /**
     * Test: SaaS Resend Verification - Works for unverified user
     * Expected: 200 OK
     */
    public function test_resend_verification_sends_email_for_unverified_user(): void
    {
        // Create unverified user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        $user = SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'unverified@example.com',
            'full_name'  => 'Test User',
            'password'   => 'password123',
            'role'       => 'owner',
            'status'     => 'unverified',
        ]);

        $response = $this->postJson('/api/auth/resend-verification', [
            'email' => 'unverified@example.com',
        ]);

        $response->assertStatus(200);

        // Verify email verification record was created
        $this->assertDatabaseHas('email_verifications', [
            'user_id' => $user->id,
            'email'   => 'unverified@example.com',
            'status'  => 'pending',
        ]);
    }

    /**
     * Test: SaaS Login - Unverified user cannot login
     * Expected: 403 Forbidden
     */
    public function test_saas_login_fails_for_unverified_user(): void
    {
        // Create unverified user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'unverified@example.com',
            'full_name'  => 'Test User',
            'password'   => 'password123',
            'role'       => 'owner',
            'status'     => 'unverified',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'unverified@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Please verify your email before logging in.',
        ]);
    }

    /**
     * Test: SaaS Login - Invalid credentials
     * Expected: 401 Unauthorized
     */
    public function test_saas_login_fails_with_invalid_credentials(): void
    {
        // Create active user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'user@example.com',
            'full_name'  => 'Test User',
            'password'   => 'correctpassword',
            'role'       => 'owner',
            'status'     => 'active',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid email or password.',
        ]);
    }

    /**
     * Test: SaaS Forgot Password - Always returns 200 (no email enumeration)
     * Expected: 200 OK
     */
    public function test_forgot_password_returns_200_for_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    /**
     * Test: SaaS Forgot Password - Creates reset token
     * Expected: 200 OK, reset token created
     */
    public function test_forgot_password_creates_reset_token(): void
    {
        // Create user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        $user = SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'user@example.com',
            'full_name'  => 'Test User',
            'password'   => 'password123',
            'role'       => 'owner',
            'status'     => 'active',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200);

        // Verify password reset record was created
        $this->assertDatabaseHas('password_resets', [
            'user_id' => $user->id,
            'status'  => 'pending',
        ]);
    }

    /**
     * Test: SaaS Reset Password - Invalid token
     * Expected: 400 Bad Request
     */
    public function test_reset_password_fails_with_invalid_token(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'           => 'invalid-token',
            'newPassword'     => 'NewPassword123',
            'confirmPassword' => 'NewPassword123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid or expired reset token.',
        ]);
    }

    /**
     * Test: SaaS Reset Password - Mismatched passwords
     * Expected: 400 Bad Request
     */
    public function test_reset_password_fails_with_mismatched_passwords(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'token'           => 'valid-token',
            'newPassword'     => 'NewPassword123',
            'confirmPassword' => 'DifferentPassword456',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Passwords do not match.',
        ]);
    }

    /**
     * Test: SaaS Update Password - Requires authentication
     * Expected: 401 Unauthorized
     */
    public function test_update_password_requires_authentication(): void
    {
        $response = $this->postJson('/api/auth/update-password', [
            'currentPassword' => 'oldpassword',
            'newPassword'     => 'newpassword123',
            'confirmPassword' => 'newpassword123',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test: SaaS Update Password - Wrong current password
     * Expected: 400 Bad Request
     */
    public function test_update_password_fails_with_wrong_current_password(): void
    {
        // Create user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        $user = SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'user@example.com',
            'full_name'  => 'Test User',
            'password'   => 'correctpassword',
            'role'       => 'owner',
            'status'     => 'active',
        ]);

        // Login to get token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'correctpassword',
        ]);

        $token = $loginResponse->json('data.token');

        // Try to update password with wrong current password
        $response = $this->postJson(
            '/api/auth/update-password',
            [
                'currentPassword' => 'wrongpassword',
                'newPassword'     => 'NewPassword123',
                'confirmPassword' => 'NewPassword123',
            ],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Current password is incorrect.',
        ]);
    }

    /**
     * Test: SaaS Me - Get current user info
     * Expected: 200 OK with user and company data
     */
    public function test_me_returns_current_user_and_company(): void
    {
        // Create user
        $company = Company::create(['name' => 'Test Corp', 'status' => 'trial']);
        $user = SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'user@example.com',
            'full_name'  => 'Test User',
            'password'   => 'password123',
            'role'       => 'owner',
            'status'     => 'active',
        ]);

        // Login
        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Get me
        $response = $this->getJson('/api/auth/me', ['Authorization' => "Bearer $token"]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'id'    => $user->id,
                    'email' => 'user@example.com',
                ],
                'company' => [
                    'id'   => $company->id,
                    'name' => 'Test Corp',
                ],
            ],
        ]);
    }

    /**
     * Test: SaaS Logout - Blacklist token
     * Expected: 200 OK
     */
    public function test_saas_logout_blacklists_token(): void
    {
        // Create and login user
        $company = Company::create(['name' => 'Test', 'status' => 'trial']);
        SaasUser::create([
            'company_id' => $company->id,
            'email'      => 'user@example.com',
            'full_name'  => 'Test User',
            'password'   => 'password123',
            'role'       => 'owner',
            'status'     => 'active',
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Logout
        $response = $this->postJson(
            '/api/auth/logout',
            [],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);

        // Token should be blacklisted
        $meResponse = $this->getJson('/api/auth/me', ['Authorization' => "Bearer $token"]);
        $meResponse->assertStatus(401);
    }
}
