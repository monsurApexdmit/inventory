<?php

namespace App\DTOs\Finance;

use App\DTOs\BaseDTO;
use App\DTOs\BaseMapper;
use App\Models\FinanceAccount;
use App\Services\Finance\FinanceMethods;
use Illuminate\Database\Eloquent\Model;

class FinanceAccountMapper extends BaseMapper
{
    /** @param array{in?:float,out?:float,count?:int,last?:?string} $activity */
    public function toDTO(Model $model, array $activity = []): BaseDTO
    {
        /** @var FinanceAccount $model */
        $keys = array_values((array) ($model->method_keys ?? []));
        $opening = round((float) $model->opening_balance, 2);
        $in = round((float) ($activity['in'] ?? 0), 2);
        $out = round((float) ($activity['out'] ?? 0), 2);

        return new FinanceAccountDTO(
            id: (int) $model->id,
            name: (string) $model->name,
            type: (string) $model->type,
            typeLabel: FinanceAccount::TYPE_LABELS[$model->type] ?? ucfirst((string) $model->type),
            methodKeys: $keys,
            methodLabels: array_map(fn ($k) => FinanceMethods::label($k), $keys),
            receivesUnmapped: (bool) $model->is_default,
            openingBalance: $opening,
            isDefault: (bool) $model->is_default,
            isActive: (bool) $model->is_active,
            note: $model->note,
            moneyIn: $in,
            moneyOut: $out,
            balance: round($opening + $in - $out, 2),
            transactionCount: (int) ($activity['count'] ?? 0),
            lastActivityDate: $activity['last'] ?? null,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
        );
    }
}
