<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;
use App\DTOs\BaseMapper;
use App\Models\FinanceTransfer;
use Illuminate\Database\Eloquent\Model;

class FinanceTransferMapper extends BaseMapper
{
    public function toDTO(Model $model): BaseDTO
    {
        /** @var FinanceTransfer $model */
        return new FinanceTransferDTO(
            id: (int) $model->id,
            fromAccountId: (int) $model->from_account_id,
            fromAccountName: $model->fromAccount?->name,
            toAccountId: (int) $model->to_account_id,
            toAccountName: $model->toAccount?->name,
            amount: round((float) $model->amount, 2),
            date: $model->date?->toDateString(),
            reference: $model->reference,
            note: $model->note,
            createdBy: $model->created_by !== null ? (int) $model->created_by : null,
            createdByName: $model->created_by_name,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
