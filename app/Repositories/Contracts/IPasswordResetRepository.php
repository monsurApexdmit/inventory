<?php

namespace App\Repositories\Contracts;

use App\Models\PasswordReset;

interface IPasswordResetRepository
{
    public function findPendingByToken(string $token): ?PasswordReset;

    public function create(array $data): PasswordReset;

    public function invalidatePendingForUser(int $userId): void;

    public function markUsed(int $id): void;
}
