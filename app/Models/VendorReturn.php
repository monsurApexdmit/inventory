<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'return_number',
        'vendor_id',
        'vendor_name',
        'total_amount',
        'status',
        'return_date',
        'completed_date',
        'credit_type',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'return_date' => 'datetime',
        'completed_date' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorReturnItem::class, 'return_id');
    }
}
