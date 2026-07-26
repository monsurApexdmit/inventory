<?php

namespace App\Repositories\Contracts;

use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Collection;

interface IShippingMethodRepository
{
    public function findByCompany(int $companyId): Collection;

    public function findForCompany(int $id, int $companyId): ShippingMethod;

    public function create(array $data): ShippingMethod;

    public function update(ShippingMethod $method, array $data): ShippingMethod;

    public function delete(ShippingMethod $method): void;
}
