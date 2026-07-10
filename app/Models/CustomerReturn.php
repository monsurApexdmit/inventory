<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'return_number',
        'customer_id',
        'customer_name',
        'order_id',
        'order_number',
        'total_amount',
        'status',
        'request_date',
        'processed_date',
        'refund_method',
        'notes',
        'processed_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'request_date' => 'datetime',
        'processed_date' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class, 'return_id');
    }
}
