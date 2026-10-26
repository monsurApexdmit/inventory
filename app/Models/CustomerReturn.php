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
        'refunded_via',
        'notes',
        'processed_by',
        'location_id',
        'restock',
        'subtotal',
        'refund_amount',
        'write_off_value',
        'reason',
        'rejection_reason',
        'is_request',
        'credited_to_customer',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'request_date' => 'datetime',
        'processed_date' => 'datetime',
        'restock' => 'boolean',
        'is_request' => 'boolean',
        'credited_to_customer' => 'boolean',
        'subtotal' => 'float',
        'refund_amount' => 'float',
        'write_off_value' => 'float',
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
