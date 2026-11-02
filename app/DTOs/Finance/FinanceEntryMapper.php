<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;
use App\DTOs\BaseMapper;
use App\Models\FinanceEntry;
use Illuminate\Database\Eloquent\Model;

class FinanceEntryMapper extends BaseMapper
{
    public function toDTO(Model $model): BaseDTO
    {
        /** @var FinanceEntry $model */
        return new FinanceEntryDTO(
            id: (int) $model->id,
            accountId: (int) $model->account_id,
            accountName: $model->account?->name,
            kind: (string) $model->kind,
            direction: (string) $model->direction,
            amount: round((float) $model->amount, 2),
            date: $model->date?->toDateString(),
            category: $model->category,
            party: $model->party,
            reference: $model->reference,
            note: $model->note,
            createdBy: $model->created_by !== null ? (int) $model->created_by : null,
            createdByName: $model->created_by_name,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            vendorId: $model->vendor_id !== null ? (int) $model->vendor_id : null,
        );
    }
}
