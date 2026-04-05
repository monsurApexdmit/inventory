<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'sell_id',
        'product_id',
        'variant_id',
        'inventory_id',
        'product_name',
        'variant_name',
        'quantity',
        'unit_price',
        'total_price',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'total_price' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    /**
     * Relationships
     */

    public function sell(): BelongsTo
    {
        return $this->belongsTo(Sell::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(VariantInventory::class, 'inventory_id');
    }
}
