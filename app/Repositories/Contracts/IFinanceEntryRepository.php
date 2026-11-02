<?php

namespace App\Repositories\Contracts;

use App\Models\FinanceEntry;

interface IFinanceEntryRepository
{
    public function findByIdAndCompany(int $id, int $companyId): ?FinanceEntry;

    public function create(array $data): FinanceEntry;

    public function update(FinanceEntry $entry, array $data): FinanceEntry;

    public function delete(FinanceEntry $entry): void;
}
