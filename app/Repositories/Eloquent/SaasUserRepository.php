<?php

namespace App\Repositories\Eloquent;

use App\Models\SaasUser;
use App\Repositories\Contracts\ISaasUserRepository;

class SaasUserRepository implements ISaasUserRepository
{
    public function __construct(private readonly SaasUser $model) {}

    public function findById(int $id): ?SaasUser
    {
        return $this->model->find($id);
    }

    public function findByEmail(string $email): ?SaasUser
    {
        return $this->model->where('email', $email)->first();
    }

    public function findByIdWithCompany(int $id): ?SaasUser
    {
        return $this->model->with('company')->find($id);
    }

    public function create(array $data): SaasUser
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): SaasUser
    {
        $user = $this->model->findOrFail($id);
        $user->update($data);

        return $user->fresh();
    }

    public function updateLastLogin(int $id): void
    {
        $this->model->where('id', $id)->update(['last_login' => now()]);
    }
}
