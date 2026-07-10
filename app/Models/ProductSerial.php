<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSerial extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'product_id',
        'variant_id',
        'location_id',
        'serial_number',
        'status',
        'purchase_order_number',
        'received_date',
        'sold_in_sell_id',
        'sold_date',
        'notes',
    ];

    protected $casts = [
        'received_date' => 'date',
        'sold_date'     => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sell(): BelongsTo
    {
        return $this->belongsTo(Sell::class, 'sold_in_sell_id');
    }
}
