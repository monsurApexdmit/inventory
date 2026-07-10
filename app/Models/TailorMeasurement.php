<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorMeasurement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_measurements';

    protected $fillable = [
        'company_id', 'customer_id', 'product_type',
        'chest', 'waist', 'hip', 'shoulder', 'sleeve', 'length',
        'neck', 'bottom_length', 'inseam', 'pajama_waist', 'pajama_length',
        'custom_fields', 'notes', 'measured_at',
    ];

    protected $casts = [
        'chest'          => 'float',
        'waist'          => 'float',
        'hip'            => 'float',
        'shoulder'       => 'float',
        'sleeve'         => 'float',
        'length'         => 'float',
        'neck'           => 'float',
        'bottom_length'  => 'float',
        'inseam'         => 'float',
        'pajama_waist'   => 'float',
        'pajama_length'  => 'float',
        'custom_fields'  => 'array',
        'measured_at'    => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TailorCustomer::class, 'customer_id');
    }
}
