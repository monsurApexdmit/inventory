<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_id',
        'sell_item_id',
        'unit_cost',
        'product_id',
        'product_name',
        'variant_id',
        'variant_name',
        'quantity',
        'price',
        'reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'float',
        'unit_cost' => 'float',
    ];

    public function return(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
