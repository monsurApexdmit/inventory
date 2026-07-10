<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'sell_id',
        'tracking_number',
        'carrier',
        'shipping_method',
        'status',
        'shipped_at',
        'estimated_delivery',
        'delivered_at',
        'shipping_cost',
        'weight',
        'dimensions',
        'notes',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'estimated_delivery' => 'datetime',
        'delivered_at' => 'datetime',
        'shipping_cost' => 'float',
        'weight' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sell(): BelongsTo
    {
        return $this->belongsTo(Sell::class);
    }

    public function trackingHistory(): HasMany
    {
        return $this->hasMany(ShipmentTrackingHistory::class, 'shipment_id')->orderBy('event_time', 'desc');
    }
}
