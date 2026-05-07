<?php

namespace App\Http\Middleware;

use App\Models\SaasUser;
use App\Models\Staff;
use App\Models\User;
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

        $userId    = $payload->get('sub');
        $companyId = $payload->get('company_id');
        $isLegacy  = $companyId === null;

        // Legacy tokens have no company_id claim — resolve via staff table
        if ($isLegacy) {
            $staff     = Staff::where('user_id', $userId)->first();
            $companyId = $staff?->company_id;
        }

        // Inject claims into the request for downstream use
        $request->attributes->set('auth_user_id',    $userId);
        $request->attributes->set('auth_company_id', $companyId);
        $request->attributes->set('auth_is_legacy',  $isLegacy);
        $request->attributes->set('auth_email',      $payload->get('email'));
        $request->attributes->set('auth_token',      $token);
        $request->setUserResolver(static fn() => $isLegacy
            ? User::find($userId)
            : SaasUser::find($userId));

        return $next($request);
    }
}
