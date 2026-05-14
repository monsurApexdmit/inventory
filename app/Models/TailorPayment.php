<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorPayment extends Model
{
    use HasFactory;

    protected $table = 'tailor_payments';

    protected $fillable = [
        'company_id', 'order_id', 'amount',
        'payment_method', 'payment_date', 'reference', 'notes',
    ];

    protected $casts = [
        'amount'       => 'float',
        'payment_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TailorOrder::class, 'order_id');
    }
}
