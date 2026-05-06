<?php

namespace App\Services\Auth;

use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\VerificationMail;
use App\Models\SaasUser;
use App\Repositories\Contracts\ICompanyRepository;
use App\Repositories\Contracts\IEmailVerificationRepository;
use App\Repositories\Contracts\IPasswordResetRepository;
use App\Repositories\Contracts\ISaasUserRepository;
use App\Repositories\Contracts\IStaffRepository;
use App\Repositories\Contracts\IUserRepository;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaasAuthService
{
    public function __construct(
        private readonly ISaasUserRepository $saasUserRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly IEmailVerificationRepository $emailVerificationRepository,
        private readonly IPasswordResetRepository $passwordResetRepository,
        private readonly JwtService $jwtService,
        private readonly IUserRepository $userRepository,
        private readonly IStaffRepository $staffRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Signup
    // -------------------------------------------------------------------------

    public function signup(array $data): array
    {
        $existingUser = $this->saasUserRepository->findByEmail($data['email']);

        if ($existingUser) {
            throw new HttpException(409, 'Email already registered.');
        }

        return DB::transaction(function () use ($data) {
            $company = $this->companyRepository->create([
                'name'          => $data['companyName'],
                'phone'         => $data['phone'],
                'email'         => $data['email'],
                'website'       => $data['website'] ?? null,
                'country'       => $data['country'] ?? null,
                'business_type' => $data['businessType'] ?? null,
                'status'        => 'trial', // not yet activated — awaiting email verify
            ]);

            $user = $this->saasUserRepository->create([
                'company_id'  => $company->id,
                'email'       => $data['email'],
                'full_name'   => $data['ownerFullName'],
                'password'    => $data['password'], // cast: hashed
                'role'        => 'owner',
                'status'      => 'unverified',
                'joined_date' => now(),
            ]);

            $token = $this->generateVerificationToken($user->id, $user->email);

            Mail::to($user->email)->send(new VerificationMail($user->full_name, $token));

            return [
                'userId'      => $user->id,
                'companyId'   => $company->id,
                'email'       => $user->email,
                'companyName' => $company->name,
                'status'      => $user->status,
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Verify Email
    // -------------------------------------------------------------------------

    public function verifyEmail(string $token): array
    {
        $verification = $this->emailVerificationRepository->findPendingByToken($token);

        if (!$verification) {
            throw new HttpException(400, 'Invalid or expired verification token.');
        }

        return DB::transaction(function () use ($verification) {
            $this->emailVerificationRepository->markUsed($verification->id);

            $user = $this->saasUserRepository->update($verification->user_id, [
                'status' => 'active',
            ]);

            // Activate trial subscription and company settings (via separate services in Phase 2)
            // For now: update company status to trial-active
            $this->companyRepository->update($user->company_id, ['status' => 'trial']);

            $issued = $this->jwtService->issueSaasToken($user);

            $company = $this->companyRepository->findById($user->company_id);

            $trialEnd  = now()->addDays(10);
            $trialDays = (int) now()->diffInDays($trialEnd);

            return [
                'userId'              => $user->id,
                'companyId'           => $user->company_id,
                'email'               => $user->email,
                'userEmail'           => $user->email,
                'companyName'         => $company->name,
                'userRole'            => $user->role,
                'companyStatus'       => $company->status,
                'token'               => $issued['token'],
                'licenseKey'          => 'trial-' . $user->company_id,
                'licenseType'         => 'trial',
                'trialStartDate'      => now()->toIso8601String(),
                'trialEndDate'        => $trialEnd->toIso8601String(),
                'trialDaysRemaining'  => $trialDays,
                'company'             => [
                    'id'        => $company->id,
                    'name'      => $company->name,
                    'status'    => $company->status,
                    'createdAt' => $company->created_at->toIso8601String(),
                    'updatedAt' => $company->updated_at->toIso8601String(),
                ],
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Resend Verification
    // -------------------------------------------------------------------------

    public function resendVerification(string $email): void
    {
        // Always silently succeed to prevent email enumeration
        $user = $this->saasUserRepository->findByEmail($email);

        if (!$user || $user->status !== 'unverified') {
            return;
        }

        $this->emailVerificationRepository->invalidatePendingForUser($user->id);

        $token = $this->generateVerificationToken($user->id, $user->email);

        Mail::to($user->email)->send(new VerificationMail($user->full_name, $token));
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    public function login(string $email, string $password): array
    {
        // Try SaaS owner/team login first
        $saasUser = $this->saasUserRepository->findByEmail($email);

        if ($saasUser) {
            if (!$saasUser->verifyPassword($password)) {
                throw new HttpException(401, 'Invalid email or password.');
            }

            if ($saasUser->status === 'unverified') {
                throw new HttpException(403, 'Please verify your email before logging in.');
            }

            if ($saasUser->status === 'inactive') {
                throw new HttpException(403, 'Your account has been deactivated.');
            }

            $this->saasUserRepository->updateLastLogin($saasUser->id);

            $issued  = $this->jwtService->issueSaasToken($saasUser);
            $company = $this->companyRepository->findById($saasUser->company_id);

            return [
                'userId'        => $saasUser->id,
                'userEmail'     => $saasUser->email,
                'companyId'     => $saasUser->company_id,
                'companyName'   => $company->name,
                'companyStatus' => $company->status,
                'userRole'      => $saasUser->role,
                'token'         => $issued['token'],
                'licenseKey'    => 'trial-' . $saasUser->company_id,
                'licenseType'   => $company->status,
                'email'         => $saasUser->email,
                'company'       => [
                    'id'        => $company->id,
                    'name'      => $company->name,
                    'status'    => $company->status,
                    'createdAt' => $company->created_at->toIso8601String(),
                    'updatedAt' => $company->updated_at->toIso8601String(),
                ],
            ];
        }

        // Fallback: staff account in legacy users table
        $legacyUser = $this->userRepository->findByEmail($email);

        if (!$legacyUser || !$legacyUser->verifyPassword($password)) {
            throw new HttpException(401, 'Invalid email or password.');
        }

        $staff = $this->staffRepository->findByUserId($legacyUser->id);

        if (!$staff) {
            throw new HttpException(401, 'Invalid email or password.');
        }

        $issued  = $this->jwtService->issueLegacyToken($legacyUser);
        $company = $this->companyRepository->findById($staff->company_id);

        return [
            'userId'        => $legacyUser->id,
            'userEmail'     => $legacyUser->email,
            'companyId'     => $staff->company_id,
            'companyName'   => $company->name,
            'companyStatus' => $company->status,
            'userRole'      => $staff->role ?? 'staff',
            'token'         => $issued['token'],
            'licenseKey'    => 'trial-' . $staff->company_id,
            'licenseType'   => $company->status,
            'email'         => $legacyUser->email,
            'company'       => [
                'id'        => $company->id,
                'name'      => $company->name,
                'status'    => $company->status,
                'createdAt' => $company->created_at->toIso8601String(),
                'updatedAt' => $company->updated_at->toIso8601String(),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------

    public function logout(string $token): void
    {
        $this->jwtService->blacklist($token);
    }

    // -------------------------------------------------------------------------
    // Forgot Password
    // -------------------------------------------------------------------------

    public function forgotPassword(string $email): void
    {
        // Always silently succeed to prevent email enumeration
        $user = $this->saasUserRepository->findByEmail($email);

        if (!$user) {
            return;
        }

        $this->passwordResetRepository->invalidatePendingForUser($user->id);

        $token = bin2hex(random_bytes(32));

        $this->passwordResetRepository->create([
            'user_id'     => $user->id,
            'email'       => $user->email,
            'reset_token' => $token,
            'expires_at'  => now()->addHour(),
            'status'      => 'pending',
        ]);

        $resetLink = config('app.frontend_url') . '/auth/reset-password?token=' . $token;

        Mail::to($user->email)->send(new PasswordResetMail($user->full_name, $resetLink));
    }

    // -------------------------------------------------------------------------
    // Reset Password
    // -------------------------------------------------------------------------

    public function resetPassword(string $token, string $newPassword, string $confirmPassword): void
    {
        if ($newPassword !== $confirmPassword) {
            throw new HttpException(400, 'Passwords do not match.');
        }

        $reset = $this->passwordResetRepository->findPendingByToken($token);

        if (!$reset) {
            throw new HttpException(400, 'Invalid or expired reset token.');
        }

        $this->saasUserRepository->update($reset->user_id, [
            'password' => $newPassword, // cast: hashed
        ]);

        $this->passwordResetRepository->markUsed($reset->id);
    }

    // -------------------------------------------------------------------------
    // Update Password (authenticated)
    // -------------------------------------------------------------------------

    public function updatePassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword): void
    {
        if ($newPassword !== $confirmPassword) {
            throw new HttpException(400, 'Passwords do not match.');
        }

        $user = $this->saasUserRepository->findById($userId);

        if (!$user || !$user->verifyPassword($currentPassword)) {
            throw new HttpException(400, 'Current password is incorrect.');
        }

        if (Hash::check($newPassword, $user->password)) {
            throw new HttpException(400, 'New password must be different from the current password.');
        }

        $this->saasUserRepository->update($userId, [
            'password' => $newPassword, // cast: hashed
        ]);
    }

    // -------------------------------------------------------------------------
    // Me
    // -------------------------------------------------------------------------

    public function me(int $userId): array
    {
        $user = $this->saasUserRepository->findByIdWithCompany($userId);

        if (!$user) {
            throw new HttpException(404, 'User not found.');
        }

        $company = $user->company;

        if (!$company) {
            throw new HttpException(404, 'Company not found.');
        }

        // Get subscription with plan
        $subscription = $company->subscriptions()->latest()->first();
        $plan = $subscription?->plan;

        $trialEnd           = now()->addDays(10);
        $trialDaysRemaining = max(0, (int) now()->diffInDays($trialEnd));

        // Get plan features and limits
        $planFeatures = [];
        $maxUsers = 10;
        $maxProducts = 1000;
        $maxBranches = 1;

        if ($plan) {
            $features = $plan->features;
            $planFeatures = is_string($features) ? (json_decode($features, true) ?? []) : ($features ?? []);
            $maxUsers = (int) ($plan->max_users ?? 10);
            $maxProducts = (int) ($plan->max_products ?? 1000);
            $maxBranches = (int) ($plan->max_branches ?? 1);
        }

        return [
            'user' => [
                'id'         => $user->id,
                'companyId'  => $user->company_id,
                'email'      => $user->email,
                'fullName'   => $user->full_name,
                'role'       => $user->role,
                'status'     => $user->status,
                'joinedDate' => $user->joined_date?->toIso8601String(),
                'lastLogin'  => $user->last_login?->toIso8601String(),
            ],
            'company' => [
                'id'                  => $company->id,
                'name'                => $company->name,
                'status'              => $company->status,
                'createdAt'           => $company->created_at->toIso8601String(),
                'updatedAt'           => $company->updated_at->toIso8601String(),
                'trialDaysRemaining'  => $trialDaysRemaining,
                'subscriptionEndDate' => $subscription?->current_period_end?->toIso8601String() ?? $trialEnd->toIso8601String(),
                'planId'              => $plan?->id,
                'planName'            => $plan?->name,
                'planFeatures'        => $planFeatures,
                'maxUsers'            => $maxUsers,
                'maxProducts'         => $maxProducts,
                'maxBranches'         => $maxBranches,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateVerificationToken(int $userId, string $email): string
    {
        $token = bin2hex(random_bytes(32));

        $this->emailVerificationRepository->create([
            'user_id'    => $userId,
            'email'      => $email,
            'token'      => $token,
            'expires_at' => now()->addHours(24),
            'status'     => 'pending',
        ]);

        return $token;
    }
}
