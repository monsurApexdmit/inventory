<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorFabric extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_fabrics';

    protected $fillable = [
        'company_id', 'name', 'fabric_type', 'color', 'pattern',
        'unit', 'purchase_price', 'selling_price', 'stock_quantity',
        'vendor_id', 'image_path', 'status',
        'code', 'hex_color', 'reorder_level',
    ];

    /** Set true to skip the automatic stock-movement log (callers log their own). */
    public bool $skipMovementLog = false;

    protected static function booted(): void
    {
        static::created(function (TailorFabric $f) {
            if ((float) $f->stock_quantity > 0) {
                TailorFabricMovement::log($f, 'opening', (float) $f->stock_quantity, 'Opening stock');
            }
        });

        static::updated(function (TailorFabric $f) {
            if ($f->skipMovementLog || !$f->wasChanged('stock_quantity')) {
                return;
            }
            $delta = (float) $f->stock_quantity - (float) $f->getOriginal('stock_quantity');
            if (abs($delta) > 0.0001) {
                TailorFabricMovement::log($f, 'adjustment', $delta, 'Stock edited');
            }
        });
    }

    public function movements(): HasMany
    {
        return $this->hasMany(TailorFabricMovement::class, 'fabric_id');
    }

    protected $casts = [
        'purchase_price'  => 'float',
        'selling_price'   => 'float',
        'stock_quantity'  => 'float',
        'reorder_level'   => 'float',
    ];

    public function vendor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Vendor::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(TailorOrderItem::class, 'fabric_id');
    }
}
