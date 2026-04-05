<?php

namespace App\Services\Auth;

use App\Models\SaasUser;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Facades\JWTFactory;

class JwtService
{
    /**
     * Issue a SaaS JWT for a SaasUser.
     * tymon encodes the model's `getJWTIdentifier()` as `sub`
     * and merges `getJWTCustomClaims()` (company_id, email) into the payload.
     */
    public function issueSaasToken(SaasUser $user): array
    {
        // Authenticate against the `api` guard so tymon knows the provider
        $token   = auth('api')->login($user);
        $expires = now()->addMinutes(config('jwt.ttl'))->toIso8601String();

        return [
            'token'   => $token,
            'expires' => $expires,
        ];
    }

    /**
     * Issue a Legacy JWT for a User.
     * Legacy claims carry only `sub` (user_id) — no custom claims.
     */
    public function issueLegacyToken(User $user): array
    {
        $token   = auth('legacy')->login($user);
        $expires = now()->addMinutes(config('jwt.ttl'))->toIso8601String();

        return [
            'token'   => $token,
            'expires' => $expires,
        ];
    }

    /**
     * Decode and return the payload for a raw token string.
     * Validates signature, expiry, and blacklist via tymon internals.
     */
    public function decode(string $token): \Tymon\JWTAuth\Payload
    {
        return JWTAuth::setToken($token)->getPayload();
    }

    /**
     * Blacklist the given raw token string.
     * Uses tymon's built-in blacklist (storage-backed).
     */
    public function blacklist(string $token): void
    {
        JWTAuth::setToken($token)->invalidate();
    }

    /**
     * Extract the bearer token from an Authorization header value.
     */
    public function extractBearerToken(string $header): ?string
    {
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
