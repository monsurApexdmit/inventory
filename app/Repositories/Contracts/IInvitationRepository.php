<?php

namespace App\Repositories\Contracts;

use App\Models\Invitation;

interface IInvitationRepository
{
    public function findByToken(string $token): ?Invitation;

    public function findById(int $id): ?Invitation;

    public function create(array $data): Invitation;

    public function update(int $id, array $data): Invitation;
}
