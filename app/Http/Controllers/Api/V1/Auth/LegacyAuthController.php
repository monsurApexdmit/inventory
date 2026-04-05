<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LegacyLoginRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Auth\LegacyAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyAuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LegacyAuthService $authService) {}

    public function login(LegacyLoginRequest $request): JsonResponse
    {
        $dto = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
        );

        $result = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return response()->json($result, 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('auth_token');
        $this->authService->logout($token);

        return $this->success(null, 'Logged out successfully.');
    }
}
