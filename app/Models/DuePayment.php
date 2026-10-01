<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DuePayment extends Model
{
    use HasFactory;

    protected $table = 'due_payments';

    protected $fillable = [
        'company_id',
        'payable_type',
        'payable_id',
        'direction',
        'amount',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'amount'       => 'float',
        'payment_date' => 'date',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
