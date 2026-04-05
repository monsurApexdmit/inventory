<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthMiddleware
{
    public function __construct(private readonly JwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        $token  = $this->jwtService->extractBearerToken($header);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Authorization token is required.',
            ], 401);
        }

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (TokenExpiredException) {
            return response()->json([
                'success' => false,
                'message' => 'Token has expired.',
            ], 401);
        } catch (TokenBlacklistedException) {
            return response()->json([
                'success' => false,
                'message' => 'Token has been revoked.',
            ], 401);
        } catch (TokenInvalidException | JWTException) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid token.',
            ], 401);
        }

        // Inject claims into the request for downstream use
        $request->attributes->set('auth_user_id',    $payload->get('sub'));
        $request->attributes->set('auth_company_id', $payload->get('company_id'));
        $request->attributes->set('auth_email',      $payload->get('email'));
        $request->attributes->set('auth_token',      $token);

        return $next($request);
    }
}
