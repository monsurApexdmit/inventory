<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SaasLoginRequest;
use App\Http\Requests\Auth\SaasSignupRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Auth\SaasAuthService;
use App\Services\Team\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaasAuthService $authService,
        private readonly TeamService $teamService,
    ) {}

    public function signup(SaasSignupRequest $request): JsonResponse
    {
        $dto = $this->authService->signup($request->validated());
        $data = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return $this->success(
            $data,
            'Account created successfully. Please check your email to verify your account.',
            201,
        );
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $dto = $this->authService->verifyEmail($request->input('token'));
        $data = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return $this->success($data, 'Email verified successfully. Trial activated for 10 days.');
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $this->authService->resendVerification($request->input('email'));

        return response()->json([
            'message' => 'If that email is registered and unverified, a new verification link has been sent.',
        ]);
    }

    public function login(SaasLoginRequest $request): JsonResponse
    {
        $dto = $this->authService->login(
            $request->input('email'),
            $request->input('password'),
        );
        $data = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return response()->json([
            'message' => 'Login successful',
            'data'    => $data,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('auth_token');
        $this->authService->logout($token);

        return $this->success(null, 'Logged out successfully.');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->input('email'));

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword(
            $request->input('token'),
            $request->input('newPassword'),
            $request->input('confirmPassword'),
        );

        return $this->success(null, 'Password reset successfully.');
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $userId = $request->attributes->get('auth_user_id');

        $this->authService->updatePassword(
            $userId,
            $request->input('currentPassword'),
            $request->input('newPassword'),
            $request->input('confirmPassword'),
        );

        return $this->success(null, 'Password updated successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $userId   = $request->attributes->get('auth_user_id');
        $isLegacy = $request->attributes->get('auth_is_legacy', false);
        $dto = $this->authService->me($userId, $isLegacy);
        $data = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return $this->success($data, 'Current user fetched');
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $dto = $this->teamService->acceptInvitation($request->all());
        $data = is_object($dto) && method_exists($dto, 'toArray') ? $dto->toArray() : $dto;

        return $this->success($data, 'Invitation accepted successfully');
    }
}
