<?php

namespace App\Repositories\Contracts;

use App\Models\SubscriptionPlan;

interface ISubscriptionPlanRepository
{
    public function findAllActive(): array;

    public function findById(int $id): ?SubscriptionPlan;
}
