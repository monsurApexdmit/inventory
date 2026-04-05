<?php

namespace App\Repositories\Contracts;

use App\Models\EmailVerification;

interface IEmailVerificationRepository
{
    public function findPendingByToken(string $token): ?EmailVerification;

    public function create(array $data): EmailVerification;

    public function invalidatePendingForUser(int $userId): void;

    public function markUsed(int $id): void;
}
