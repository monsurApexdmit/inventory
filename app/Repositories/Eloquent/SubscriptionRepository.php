<?php

namespace App\Repositories\Eloquent;

use App\Models\Subscription;
use App\Repositories\Contracts\ISubscriptionRepository;

class SubscriptionRepository implements ISubscriptionRepository
{
    public function __construct(private readonly Subscription $model)
    {
    }

    public function findByCompanyId(int $companyId): ?Subscription
    {
        return $this->model->with('plan')->where('company_id', $companyId)->latest()->first();
    }

    public function create(array $data): Subscription
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Subscription
    {
        $record = $this->model->findOrFail($id);
        $record->update($data);

        return $this->model->with('plan')->findOrFail($id);
    }
}
