<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\IUserRepository;

class UserRepository implements IUserRepository
{
    public function __construct(private readonly User $model) {}

    public function findById(int $id): ?User
    {
        return $this->model->with('role')->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->with('role')->where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): User
    {
        $user = $this->model->findOrFail($id);
        $user->update($data);

        return $user->fresh('role');
    }

    public function softDelete(int $id): void
    {
        $this->model->findOrFail($id)->delete();
    }
}
