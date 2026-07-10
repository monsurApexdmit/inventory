<?php

namespace App\Repositories\Eloquent;

use App\Models\Invitation;
use App\Repositories\Contracts\IInvitationRepository;

class InvitationRepository implements IInvitationRepository
{
    public function __construct(private readonly Invitation $model)
    {
    }

    public function findByToken(string $token): ?Invitation
    {
        return $this->model->where('invitation_token', $token)->first();
    }

    public function findById(int $id): ?Invitation
    {
        return $this->model->find($id);
    }

    public function create(array $data): Invitation
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Invitation
    {
        $record = $this->model->findOrFail($id);
        $record->update($data);

        return $record;
    }
}
