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
use App\Models\Location;
use App\Models\Setting;
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
            // Already verified → genuine duplicate, send them to login.
            if ($existingUser->status !== 'unverified') {
                throw new HttpException(409, 'Email already registered.');
            }

            // Registered but never verified → resend the verification email
            // instead of erroring, so the user can complete signup.
            $this->emailVerificationRepository->invalidatePendingForUser($existingUser->id);

            $token = $this->generateVerificationToken($existingUser->id, $existingUser->email);

            Mail::to($existingUser->email)->send(new VerificationMail($existingUser->full_name, $token));

            return [
                'userId'      => $existingUser->id,
                'companyId'   => $existingUser->company_id,
                'email'       => $existingUser->email,
                'companyName' => $existingUser->company->name ?? '',
                'status'      => $existingUser->status,
                'resent'      => true,
            ];
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

            // Update company status to trial-active
            $this->companyRepository->update($user->company_id, ['status' => 'trial']);

            $company = $this->companyRepository->findById($user->company_id);

            // Activate the 10-day trial subscription so the billing pages have
            // real plan + period data (otherwise the UI shows NaN / Invalid Date).
            $trialStart = now();
            $trialEnd   = now()->addDays(10);
            $trialPlan  = \DB::table('subscription_plans')->where('name', 'Trial')->first();

            \DB::table('subscriptions')->updateOrInsert(
                ['company_id' => $user->company_id],
                [
                    'plan_id'              => $trialPlan->id ?? null,
                    'status'               => 'trialing',
                    'current_period_start' => $trialStart,
                    'current_period_end'   => $trialEnd,
                    'next_billing_date'    => $trialEnd,
                    'auto_renew'           => false,
                    'updated_at'           => $trialStart,
                    'created_at'           => $trialStart,
                ]
            );

            Location::firstOrCreate(
                ['company_id' => $user->company_id, 'is_default' => true],
                ['name' => $company->name . ' - Main Store']
            );

            Setting::firstOrCreate(
                ['company_id' => $user->company_id]
            );

            $issued = $this->jwtService->issueSaasToken($user);

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

            $issued = $this->jwtService->issueSaasToken($saasUser);

            // Super admin: no company
            if ($saasUser->role === 'super_admin') {
                return [
                    'userId'    => $saasUser->id,
                    'userEmail' => $saasUser->email,
                    'email'     => $saasUser->email,
                    'userRole'  => 'super_admin',
                    'companyId' => null,
                    'token'     => $issued['token'],
                ];
            }

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

    public function me(int $userId, bool $isLegacy = false): array
    {
        // Legacy staff tokens: JWT has no company_id claim
        if ($isLegacy) {
            return $this->meAsStaff($userId);
        }

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

        // Get plan features, modules and limits
        $planFeatures = [];
        $planModules  = [];
        $maxUsers     = 10;
        $maxProducts  = 1000;
        $maxBranches  = 1;

        if ($plan) {
            $features     = $plan->features;
            $modules      = $plan->modules;
            $planFeatures = is_string($features) ? (json_decode($features, true) ?? []) : ($features ?? []);
            $planModules  = is_string($modules)  ? (json_decode($modules,  true) ?? []) : ($modules  ?? []);
            $maxUsers     = (int) ($plan->max_users    ?? 10);
            $maxProducts  = (int) ($plan->max_products ?? 1000);
            $maxBranches  = (int) ($plan->max_branches ?? 1);
        }

        return [
            'user' => [
                'id'          => $user->id,
                'companyId'   => $user->company_id,
                'email'       => $user->email,
                'fullName'    => $user->full_name,
                'role'        => $user->role,
                'status'      => $user->status,
                'joinedDate'  => $user->joined_date?->toIso8601String(),
                'lastLogin'   => $user->last_login?->toIso8601String(),
                'permissions' => null, // null = full access (owner/admin bypass)
                'roleId'      => $user->role_id,
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
                'planModules'         => $planModules,
                'maxUsers'            => $maxUsers,
                'maxProducts'         => $maxProducts,
                'maxBranches'         => $maxBranches,
            ],
        ];
    }

    private function meAsStaff(int $legacyUserId): array
    {
        $legacyUser = $this->userRepository->findById($legacyUserId);

        if (!$legacyUser) {
            throw new HttpException(404, 'User not found.');
        }

        $staff = $this->staffRepository->findByUserId($legacyUserId);

        if (!$staff) {
            throw new HttpException(404, 'Staff record not found.');
        }

        $company = $this->companyRepository->findById($staff->company_id);

        if (!$company) {
            throw new HttpException(404, 'Company not found.');
        }

        $subscription = $company->subscriptions()->latest()->first();
        $plan = $subscription?->plan;

        $trialEnd           = now()->addDays(10);
        $trialDaysRemaining = max(0, (int) now()->diffInDays($trialEnd));

        $planFeatures = [];
        $planModules  = [];
        $maxUsers     = 10;
        $maxProducts  = 1000;
        $maxBranches  = 1;

        if ($plan) {
            $features     = $plan->features;
            $modules      = $plan->modules;
            $planFeatures = is_string($features) ? (json_decode($features, true) ?? []) : ($features ?? []);
            $planModules  = is_string($modules)  ? (json_decode($modules,  true) ?? []) : ($modules  ?? []);
            $maxUsers     = (int) ($plan->max_users    ?? 10);
            $maxProducts  = (int) ($plan->max_products ?? 1000);
            $maxBranches  = (int) ($plan->max_branches ?? 1);
        }

        // Build permission map from custom role_permissions table
        $permissions = [];
        if ($staff->staff_role_id) {
            $rolePerms = \App\Models\RolePermission::with('permission')
                ->where('role_id', $staff->staff_role_id)
                ->get();

            foreach ($rolePerms as $rp) {
                if ($rp->permission) {
                    $permissions[$rp->permission->name] = [
                        'read'   => (bool) $rp->read,
                        'write'  => (bool) $rp->write,
                        'delete' => (bool) $rp->delete,
                    ];
                }
            }
        }

        return [
            'user' => [
                'id'          => $legacyUser->id,
                'companyId'   => $staff->company_id,
                'email'       => $legacyUser->email,
                'fullName'    => $staff->name,
                'role'        => $staff->role ?? 'staff',
                'status'      => $staff->status ?? 'active',
                'joinedDate'  => $staff->joining_date ?? null,
                'lastLogin'   => null,
                'permissions' => $permissions, // keyed by module name
                'roleId'      => $staff->staff_role_id,
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
                'planModules'         => $planModules,
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
