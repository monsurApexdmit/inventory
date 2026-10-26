<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment against a vendor's opening balance (not tied to a purchase order).
 */
class VendorPayment extends Model
{
    protected $fillable = [
        'company_id',
        'vendor_id',
        'amount',
        'payment_method',
        'payment_date',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'payment_date' => 'date:Y-m-d',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
