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
        'cod_amount',
        'cod_collected',
        'cod_collected_amount',
        'cod_collected_at',
        'rider',
        'package',
        'delivery_payer',
        'delivery_speed',
        'pickup_date',
        'weight',
        'dimensions',
        'notes',
    ];

    protected $casts = [
        'shipped_at' => 'datetime',
        'estimated_delivery' => 'datetime',
        'delivered_at' => 'datetime',
        'shipping_cost' => 'float',
        'cod_amount' => 'float',
        'cod_collected' => 'boolean',
        'cod_collected_amount' => 'float',
        'cod_collected_at' => 'datetime',
        'pickup_date' => 'date:Y-m-d',
        'weight' => 'float',
    ];

    /**
     * Shipment statuses: pending, picked_up, in_transit, out_for_delivery, delivered, failed, returned.
     * There is no dedicated `cancelled` status; a shipment that is cancelled/voided ends as `failed`
     * (or `returned`), and 'cancelled' is listed here defensively. A "live" shipment is any shipment
     * not in one of these dead states, so `delivered` still counts as live (the order is shipped and
     * must not get a second shipment). Use this scope / isLive() everywhere instead of ad-hoc lists.
     */
    public const DEAD_STATUSES = ['failed', 'returned', 'cancelled', 'canceled'];

    public function scopeLive($query)
    {
        return $query->whereNotIn('order_shipments.status', self::DEAD_STATUSES);
    }

    public function isLive(): bool
    {
        return !in_array(strtolower((string) $this->status), self::DEAD_STATUSES, true);
    }

    /** Live and not yet delivered: the shipment can still change state. */
    public function isOpen(): bool
    {
        return $this->isLive() && strtolower((string) $this->status) !== 'delivered';
    }

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
