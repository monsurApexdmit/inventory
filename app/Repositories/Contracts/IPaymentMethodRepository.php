<?php

namespace App\Repositories\Contracts;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

interface IPaymentMethodRepository
{
    public function findByCompany(int $companyId): Collection;

    public function findForCompany(int $id, int $companyId): PaymentMethod;

    public function create(array $data): PaymentMethod;

    public function update(PaymentMethod $method, array $data): PaymentMethod;

    public function delete(PaymentMethod $method): void;
}
