<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorOrderItem extends Model
{
    use HasFactory;

    protected $table = 'tailor_order_items';

    protected $fillable = [
        'order_id', 'product_type', 'fabric_id',
        'fabric_quantity', 'fabric_unit_price',
        'measurement_id', 'notes',
    ];

    protected $casts = [
        'fabric_quantity'   => 'float',
        'fabric_unit_price' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TailorOrder::class, 'order_id');
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(TailorFabric::class, 'fabric_id');
    }

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(TailorMeasurement::class, 'measurement_id');
    }
}
