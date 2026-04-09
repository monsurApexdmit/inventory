<?php

namespace App\Repositories\Eloquent;

use App\Models\SubscriptionPlan;
use App\Repositories\Contracts\ISubscriptionPlanRepository;

class SubscriptionPlanRepository implements ISubscriptionPlanRepository
{
    public function __construct(private readonly SubscriptionPlan $model)
    {
    }

    public function findAllActive(): array
    {
        return $this->model->where('is_active', true)->get()->toArray();
    }

    public function findById(int $id): ?SubscriptionPlan
    {
        return $this->model->find($id);
    }

    public function update(int $id, array $data): SubscriptionPlan
    {
        $plan = $this->model->findOrFail($id);
        $plan->update($data);

        return $plan->fresh();
    }
}
