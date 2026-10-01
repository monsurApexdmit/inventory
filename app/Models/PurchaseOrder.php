<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'location_id',
        'po_number',
        'status',
        'expected_date',
        'notes',
        'total_amount',
        'paid_amount',
        'due_amount',
        'payment_status',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'expected_date' => 'date:Y-m-d',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(DuePayment::class, 'payable')->orderByDesc('payment_date')->orderByDesc('id');
    }

    public function recalculate(): void
    {
        $this->paid_amount = $this->payments()->where('direction', 'out')->sum('amount');
        $this->due_amount  = max(0, $this->total_amount - $this->paid_amount);
        if ($this->paid_amount <= 0) {
            $this->payment_status = 'pending';
        } elseif ($this->due_amount <= 0) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partially_paid';
        }
        $this->save();
    }
}
