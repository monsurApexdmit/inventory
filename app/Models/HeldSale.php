<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeldSale extends Model
{
    protected $table = 'held_sales';

    protected $fillable = [
        'company_id',
        'label',
        'customer_id',
        'item_count',
        'total',
        'payload',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'total' => 'decimal:2',
        'item_count' => 'integer',
    ];
}
