<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface IUserRepository
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function create(array $data): User;

    public function update(int $id, array $data): User;

    public function softDelete(int $id): void;
}
