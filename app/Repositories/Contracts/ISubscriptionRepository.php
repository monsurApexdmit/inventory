<?php

namespace App\Repositories\Contracts;

use App\Models\Subscription;

interface ISubscriptionRepository
{
    public function findByCompanyId(int $companyId): ?Subscription;

    public function create(array $data): Subscription;

    public function update(int $id, array $data): Subscription;
}
