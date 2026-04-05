<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a role for testing
        Role::create([
            'title'  => 'Admin',
            'status' => true,
        ]);
    }

    /**
     * Test: Legacy Login - Valid credentials
     * Expected: 200 OK with JWT token
     */
    public function test_legacy_login_with_valid_credentials(): void
    {
        // Create user
        User::create([
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => 'password123',
            'role_id'  => 1,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'testuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Login successful',
        ]);
        $response->assertJsonStructure([
            'token',
            'expires',
        ]);

        // Verify token is a valid JWT
        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertStringContainsString('.', $token); // JWT has dots
    }

    /**
     * Test: Legacy Login - Invalid password
     * Expected: 401 Unauthorized
     */
    public function test_legacy_login_fails_with_invalid_password(): void
    {
        // Create user
        User::create([
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => 'correctpassword',
            'role_id'  => 1,
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'testuser@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid email or password.',
        ]);
    }

    /**
     * Test: Legacy Login - Nonexistent user
     * Expected: 401 Unauthorized
     */
    public function test_legacy_login_fails_with_nonexistent_user(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid email or password.',
        ]);
    }

    /**
     * Test: Legacy Login - Missing email
     * Expected: 422 Unprocessable Entity
     */
    public function test_legacy_login_fails_with_missing_email(): void
    {
        $response = $this->postJson('/api/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    /**
     * Test: Legacy Login - Missing password
     * Expected: 422 Unprocessable Entity
     */
    public function test_legacy_login_fails_with_missing_password(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    /**
     * Test: Legacy Logout - Valid token
     * Expected: 200 OK
     */
    public function test_legacy_logout_with_valid_token(): void
    {
        // Create and login user
        User::create([
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => 'password123',
            'role_id'  => 1,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email'    => 'testuser@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('token');

        // Logout
        $response = $this->postJson(
            '/api/logout',
            [],
            ['Authorization' => "Bearer $token"]
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Test: Legacy Logout - Without token
     * Expected: 401 Unauthorized
     */
    public function test_legacy_logout_fails_without_token(): void
    {
        $response = $this->postJson('/api/logout', []);

        $response->assertStatus(401);
    }

    /**
     * Test: Legacy Logout - Token blacklisted
     * Expected: Token cannot be used after logout
     */
    public function test_legacy_logout_blacklists_token(): void
    {
        // Create and login user
        User::create([
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => 'password123',
            'role_id'  => 1,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email'    => 'testuser@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('token');

        // Logout
        $logoutResponse = $this->postJson(
            '/api/logout',
            [],
            ['Authorization' => "Bearer $token"]
        );

        $logoutResponse->assertStatus(200);

        // Try to logout again with same token - should fail (token blacklisted)
        $logoutAgain = $this->postJson(
            '/api/logout',
            [],
            ['Authorization' => "Bearer $token"]
        );
        $logoutAgain->assertStatus(401);
    }

    /**
     * Test: Legacy Logout - Invalid token format
     * Expected: 401 Unauthorized
     */
    public function test_legacy_logout_fails_with_invalid_token(): void
    {
        $response = $this->postJson(
            '/api/logout',
            [],
            ['Authorization' => 'Bearer invalid-token-format']
        );

        $response->assertStatus(401);
    }

    /**
     * Test: Auth Me - Without token
     * Expected: 401 Unauthorized
     */
    public function test_auth_me_requires_token(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    /**
     * Test: Auth Me - With invalid token
     * Expected: 401 Unauthorized
     */
    public function test_auth_me_fails_with_invalid_token(): void
    {
        $response = $this->getJson('/api/auth/me', ['Authorization' => 'Bearer invalid-token']);

        $response->assertStatus(401);
    }

    /**
     * Test: Auth Me - Legacy user should get 500 (endpoint is for SaaS only)
     * Expected: 500 error because legacy user doesn't have company relationship
     * Note: This test documents the current behavior. Future work should separate endpoints.
     */
    public function test_auth_me_with_legacy_token_returns_error(): void
    {
        // Create user
        $user = User::create([
            'username' => 'testuser',
            'email'    => 'testuser@example.com',
            'password' => 'password123',
            'role_id'  => 1,
        ]);

        // Login
        $loginResponse = $this->postJson('/api/login', [
            'email'    => 'testuser@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('token');

        // Get me - will fail because endpoint expects SaaS user with company
        // This documents current behavior - /auth/me is SaaS-only
        $response = $this->getJson('/api/auth/me', ['Authorization' => "Bearer $token"]);

        // Currently returns 500, should ideally return 401 or separate endpoint
        $response->assertStatus(500);
    }
}
