<?php

namespace App\Repositories\Contracts;

use App\Models\DuePayment;
use Illuminate\Support\Collection;

interface IDuePaymentRepository
{
    public function create(array $data): DuePayment;

    public function findByPayable(string $type, int $id): Collection;
}
