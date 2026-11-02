<?php

namespace App\Repositories\Contracts;

use App\Models\FinanceAccount;
use Illuminate\Support\Collection;

interface IFinanceAccountRepository
{
    /** @return Collection<int, FinanceAccount> default first, then by name */
    public function allByCompany(int $companyId): Collection;

    public function countByCompany(int $companyId): int;

    public function findByIdAndCompany(int $id, int $companyId): ?FinanceAccount;

    public function nameExists(int $companyId, string $name, ?int $exceptId = null): bool;

    public function create(array $data): FinanceAccount;

    public function update(FinanceAccount $account, array $data): FinanceAccount;

    public function delete(FinanceAccount $account): void;

    /** Unset is_default on every other account of the company. */
    public function clearDefault(int $companyId, ?int $exceptId = null): void;

    /** True when manual entries or transfers reference the account. */
    public function hasActivity(int $accountId): bool;
}
