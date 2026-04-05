<?php

namespace App\Repositories\Contracts;

use App\Models\Coupon;
use Illuminate\Pagination\LengthAwarePaginator;

interface ICouponRepository extends BaseRepositoryInterface
{
    public function findByCompany(int $companyId, array $filters = []): LengthAwarePaginator;

    public function findByIdAndCompany(int $id, int $companyId): ?Coupon;

    public function findByCode(string $code): ?Coupon;

    public function findByCodeAndCompany(string $code, int $companyId): ?Coupon;

    public function getUsageStats(int $couponId): array;
}
