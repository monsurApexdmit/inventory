<?php

namespace App\Repositories\Contracts;

use App\Models\SaasUser;

interface ISaasUserRepository
{
    public function findById(int $id): ?SaasUser;

    public function findByEmail(string $email): ?SaasUser;

    public function findByIdWithCompany(int $id): ?SaasUser;

    public function create(array $data): SaasUser;

    public function update(int $id, array $data): SaasUser;

    public function updateLastLogin(int $id): void;
}
