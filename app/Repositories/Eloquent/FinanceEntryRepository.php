<?php

namespace App\Repositories\Eloquent;

use App\Models\FinanceEntry;
use App\Repositories\Contracts\IFinanceEntryRepository;

class FinanceEntryRepository implements IFinanceEntryRepository
{
    public function __construct(private readonly FinanceEntry $model)
    {
    }

    public function findByIdAndCompany(int $id, int $companyId): ?FinanceEntry
    {
        return $this->model->newQuery()->with('account')->where('company_id', $companyId)->find($id);
    }

    public function create(array $data): FinanceEntry
    {
        return $this->model->newQuery()->create($data)->load('account');
    }

    public function update(FinanceEntry $entry, array $data): FinanceEntry
    {
        $entry->fill($data)->save();

        return $entry->fresh('account');
    }

    public function delete(FinanceEntry $entry): void
    {
        $entry->delete();
    }
}
