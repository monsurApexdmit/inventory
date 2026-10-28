<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money moved between two of the company's accounts (two ledger lines, net zero). */
class FinanceTransfer extends Model
{
    protected $table = 'finance_transfers';

    protected $fillable = [
        'company_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'date',
        'reference',
        'note',
        'created_by',
        'created_by_name',
    ];

    protected $casts = [
        'amount' => 'float',
        'date' => 'date',
    ];

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'to_account_id');
    }
}
