<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A place money is kept (cash drawer, bank account, bKash/Nagad wallet …). */
class FinanceAccount extends Model
{
    protected $table = 'finance_accounts';

    public const TYPES = ['cash', 'bank', 'mobile_wallet', 'card', 'other'];

    public const TYPE_LABELS = [
        'cash' => 'Cash',
        'bank' => 'Bank',
        'mobile_wallet' => 'Mobile wallet',
        'card' => 'Card',
        'other' => 'Other',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'method_keys',
        'opening_balance',
        'is_default',
        'is_active',
        'note',
    ];

    protected $casts = [
        'method_keys' => 'array',
        'opening_balance' => 'float',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
