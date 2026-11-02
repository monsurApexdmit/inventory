<?php

namespace App\Repositories\Eloquent;

use App\Models\FinanceAccount;
use App\Models\FinanceEntry;
use App\Models\FinanceTransfer;
use App\Repositories\Contracts\IFinanceAccountRepository;
use Illuminate\Support\Collection;

class FinanceAccountRepository implements IFinanceAccountRepository
{
    public function __construct(private readonly FinanceAccount $model)
    {
    }

    public function allByCompany(int $companyId): Collection
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->orderByDesc('is_default')->orderBy('id')->get();
    }

    public function countByCompany(int $companyId): int
    {
        return $this->model->newQuery()->where('company_id', $companyId)->count();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?FinanceAccount
    {
        return $this->model->newQuery()->where('company_id', $companyId)->find($id);
    }

    public function nameExists(int $companyId, string $name, ?int $exceptId = null): bool
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function create(array $data): FinanceAccount
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(FinanceAccount $account, array $data): FinanceAccount
    {
        $account->fill($data)->save();

        return $account->fresh();
    }

    public function delete(FinanceAccount $account): void
    {
        $account->delete();
    }

    public function clearDefault(int $companyId, ?int $exceptId = null): void
    {
        $this->model->newQuery()->where('company_id', $companyId)->where('is_default', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    public function hasActivity(int $accountId): bool
    {
        return FinanceEntry::where('account_id', $accountId)->exists()
            || FinanceTransfer::where('from_account_id', $accountId)->orWhere('to_account_id', $accountId)->exists();
    }
}
