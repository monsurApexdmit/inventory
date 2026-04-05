<?php

namespace App\Repositories\Eloquent;

use App\Models\EmailVerification;
use App\Repositories\Contracts\IEmailVerificationRepository;

class EmailVerificationRepository implements IEmailVerificationRepository
{
    public function __construct(private readonly EmailVerification $model) {}

    public function findPendingByToken(string $token): ?EmailVerification
    {
        return $this->model
            ->where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function create(array $data): EmailVerification
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
