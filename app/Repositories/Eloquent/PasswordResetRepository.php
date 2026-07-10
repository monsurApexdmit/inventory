<?php

namespace App\Repositories\Eloquent;

use App\Models\PasswordReset;
use App\Repositories\Contracts\IPasswordResetRepository;

class PasswordResetRepository implements IPasswordResetRepository
{
    public function __construct(private readonly PasswordReset $model) {}

    public function findPendingByToken(string $token): ?PasswordReset
    {
        return $this->model
            ->where('reset_token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function create(array $data): PasswordReset
    {
        return $this->model->create($data);
    }

    public function invalidatePendingForUser(int $userId): void
    {
        $this->model
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }

    public function markUsed(int $id): void
    {
        $this->model->where('id', $id)->update([
            'status'  => 'used',
            'used_at' => now(),
        ]);
    }
}
