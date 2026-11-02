<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Manual ledger line: other income, other expense or a balance adjustment on one account. */
class FinanceEntry extends Model
{
    protected $table = 'finance_entries';

    public const KINDS = ['income', 'expense', 'adjustment'];

    /** Money received from a supplier who owed us (credit balance); not in P&L. */
    public const KIND_SUPPLIER_RECEIPT = 'supplier_receipt';

    protected $fillable = [
        'company_id',
        'account_id',
        'vendor_id',
        'kind',
        'direction',
        'amount',
        'date',
        'category',
        'party',
        'reference',
        'note',
        'created_by',
        'created_by_name',
    ];

    protected $casts = [
        'amount' => 'float',
        'date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }
}
