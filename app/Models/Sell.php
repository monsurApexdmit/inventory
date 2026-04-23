<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Sell extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sells';

    protected $fillable = [
        'company_id',
        'invoice_no',
        'order_time',
        'customer_id',
        'customer_name',
        'shipping_address_id',
        'shipping_full_name',
        'shipping_phone',
        'shipping_email',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country',
        'shipping_address_type',
        'method',
        'amount',
        'shipping_cost',
        'shipping_method',
        'coupon_id',
        'coupon_code',
        'discount',
        'status',
        'stock_deducted',
        'payment_status',
        'fulfillment_status',
        'tracking_number',
        'carrier',
        'shipped_at',
        'delivered_at',
        'total_cost',
        'gross_profit',
        'notes',
    ];

    protected $casts = [
        'amount' => 'float',
        'shipping_cost' => 'float',
        'discount' => 'float',
        'stock_deducted' => 'boolean',
        'total_cost' => 'float',
        'gross_profit' => 'float',
        'order_time' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Relationships
     */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(ShippingAddress::class, 'shipping_address_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'sell_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class, 'sell_id');
    }

    public function shippingMethodModel(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method');
    }
}
